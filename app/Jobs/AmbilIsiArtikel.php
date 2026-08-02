<?php

namespace App\Jobs;

use App\Models\Artikel;
use App\Services\Crawler\EkstraktorArtikel;
use App\Services\Crawler\EkstraktorWordPress;
use App\Services\Crawler\GagalMengunduh;
use App\Services\Crawler\PengunduhHalaman;
use App\Services\Crawler\UrlDitolak;
use App\Services\Dedup\PencariDuplikat;
use App\Services\Dedup\PenghitungSimhash;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Mengunduh halaman artikel, mengekstrak isinya, lalu menjalankan deduplikasi
 * lapis 1 dan 2.
 *
 * Rantai berhenti di sini kalau artikel ternyata salinan. Analisis NLP
 * (sprint 3) hanya dijadwalkan untuk artikel asli, supaya biaya inferensi
 * tidak dibayar berulang untuk rilis yang sama.
 */
class AmbilIsiArtikel implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $artikelId)
    {
        $this->onQueue('crawl');
    }

    /** Backoff eksponensial: situs yang sedang bermasalah diberi waktu pulih. */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        PengunduhHalaman $pengunduh,
        EkstraktorWordPress $wordpress,
        EkstraktorArtikel $ekstraktor,
        PenghitungSimhash $simhash,
        PencariDuplikat $dedup,
    ): void {
        $artikel = Artikel::withoutGlobalScopes()->find($this->artikelId);

        if ($artikel === null || $artikel->status_proses === 'selesai') {
            return;
        }

        $hasil = null;
        $galat = null;

        try {
            $hasil = $ekstraktor->ekstrak($pengunduh->unduh($artikel->url), $artikel->url);
        } catch (UrlDitolak $e) {
            // URL internal atau dilarang robots.txt. Mengulang tidak akan
            // mengubah hasilnya, jadi jangan buang tiga percobaan.
            $this->tandaiGagal($artikel, $e->getMessage());
            $this->fail($e);

            return;
        } catch (GagalMengunduh $e) {
            // Halamannya tidak bisa diunduh; API mungkin masih menjawab.
            $galat = $e;
        }

        // Jaring pengaman WordPress, bukan jalur utama.
        //
        // 26 dari 30 media partner memakai WordPress, jadi godaannya besar
        // untuk menaruh API di depan. Pengukuran menolak itu: halaman HTML
        // dilayani page cache dalam 147 ms, sedangkan /wp-json/ menembus cache
        // dan menjalankan PHP serta query MySQL — 422 ms, tiga kali lebih
        // lambat, dan jauh lebih mahal bagi hosting kecil yang dipakai media
        // daerah. Yang tetap dimenangkan API adalah kepastian datanya, dan itu
        // baru bernilai justru ketika Readability gagal atau hasilnya kependekan.
        if ($hasil === null || $hasil->terlaluPendek()) {
            $dariApi = $wordpress->ekstrak($artikel->url);

            if ($dariApi !== null && ($hasil === null || $dariApi->jumlahKata > $hasil->jumlahKata)) {
                $hasil = $dariApi;
            }
        }

        if ($hasil === null) {
            $this->tandaiGagal($artikel, $galat->getMessage());

            throw $galat;
        }

        $artikel->fill([
            // Judul feed sering lebih rapi daripada judul hasil ekstraksi;
            // hasil ekstraksi hanya dipakai kalau judul feed kosong.
            'judul' => $artikel->judul ?: ($hasil->judul ?? $artikel->judul),
            'isi' => $hasil->isi,
            'ringkasan' => $artikel->ringkasan ?: $hasil->ringkasan,
            'penulis' => $artikel->penulis ?: $hasil->penulis,
            'gambar_url' => $hasil->gambarUrl ? mb_substr($hasil->gambarUrl, 0, 1000) : null,
            'jumlah_kata' => $hasil->jumlahKata,
            // Tanggal dari metadata halaman lebih bisa dipercaya daripada
            // pubDate feed, tapi jangan menimpa nilai yang sudah ada.
            'dipublikasikan_at' => $artikel->dipublikasikan_at ?? $hasil->dipublikasikanAt,
            'status_proses' => 'isi_diambil',
            'pesan_gagal' => null,
        ]);

        if ($hasil->isi !== null && $hasil->isi !== '') {
            $artikel->hash_isi = $dedup->hashIsi($hasil->isi);
            $artikel->simhash = $simhash->hitung($artikel->judul.' '.$hasil->isi);
        }

        $artikel->save();

        if ($hasil->isi === null || $hasil->isi === '') {
            // Ekstraksi kosong bukan kegagalan fatal: judul dan URL sudah cukup
            // untuk pencocokan pemuatan kontrak. Ditandai supaya bisa diaudit.
            Log::warning('Ekstraksi isi kosong', ['artikel_id' => $artikel->id, 'url' => $artikel->url]);
            $artikel->update(['pesan_gagal' => 'Isi artikel tidak dapat diekstrak dari halaman.']);

            return;
        }

        $induk = $dedup->cariInduk($artikel);

        if ($induk !== null) {
            $dedup->tandaiSalinan($artikel, $induk);

            return;
        }

        // Dedup lapis 1 dan 2 sudah lewat tanpa temuan. Lanjut ke lapis 3 dan
        // analisis, yang seluruhnya berjalan di antrean `nlp`.
        HitungEmbedding::dispatch($artikel->id);
    }

    public function failed(\Throwable $e): void
    {
        Artikel::withoutGlobalScopes()
            ->where('id', $this->artikelId)
            ->update(['status_proses' => 'gagal', 'pesan_gagal' => mb_substr($e->getMessage(), 0, 1000)]);
    }

    private function tandaiGagal(Artikel $artikel, string $pesan): void
    {
        $artikel->update(['pesan_gagal' => mb_substr($pesan, 0, 1000)]);
    }
}
