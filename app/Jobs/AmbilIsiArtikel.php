<?php

namespace App\Jobs;

use App\Models\Artikel;
use App\Services\Crawler\EkstraktorArtikel;
use App\Services\Crawler\EkstraktorWordPress;
use App\Services\Crawler\GagalMengunduh;
use App\Services\Crawler\PengunduhHalaman;
use App\Services\Crawler\PenyelesaiArtikel;
use App\Services\Crawler\UrlDitolak;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        PenyelesaiArtikel $penyelesai,
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

        // Sidik jari, deduplikasi, dan penerusan ke analisis dikerjakan satu
        // tempat bersama jalur backfill, supaya keduanya tidak pernah berbeda
        // perlakuan.
        $penyelesai->selesaikan($artikel, $hasil);
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
