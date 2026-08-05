<?php

namespace App\Services\Crawler;

use App\Models\Artikel;
use App\Services\Dedup\PencariDuplikat;
use App\Services\Dedup\PenghitungSimhash;
use Illuminate\Support\Facades\Log;

/**
 * Langkah setelah isi artikel tersedia: simpan, hitung sidik jari, lalu
 * deduplikasi. Artikel yang lolos berhenti di sini menunggu diklasifikasi.
 *
 * Dipakai dua jalur, pengambilan halaman satu per satu (AmbilIsiArtikel) dan
 * penarikan arsip massal (crawl:backfill). Keduanya wajib memproses artikel
 * dengan cara yang persis sama: kalau deduplikasinya berbeda sedikit saja, satu
 * peristiwa terhitung dua kali dan seluruh angka dashboard ikut salah tanpa ada
 * yang menyadarinya.
 */
class PenyelesaiArtikel
{
    public function __construct(
        private PenghitungSimhash $simhash,
        private PencariDuplikat $dedup,
    ) {}

    /**
     * @return bool true kalau artikel diteruskan ke analisis, false kalau
     *              berhenti di sini, salinan, atau isinya kosong
     */
    public function selesaikan(Artikel $artikel, HasilEkstraksi $hasil): bool
    {
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

        $adaIsi = $hasil->isi !== null && $hasil->isi !== '';

        if ($adaIsi) {
            $artikel->hash_isi = $this->dedup->hashIsi($hasil->isi);
            $artikel->simhash = $this->simhash->hitung($artikel->judul.' '.$hasil->isi);
        }

        $artikel->save();

        if (! $adaIsi) {
            // Ekstraksi kosong bukan kegagalan fatal: judul dan URL sudah cukup
            // untuk pencocokan pemuatan kontrak. Ditandai supaya bisa diaudit.
            Log::warning('Ekstraksi isi kosong', ['artikel_id' => $artikel->id, 'url' => $artikel->url]);
            $artikel->update(['pesan_gagal' => 'Isi artikel tidak dapat diekstrak dari halaman.']);

            return false;
        }

        $induk = $this->dedup->cariInduk($artikel);

        if ($induk !== null) {
            $this->dedup->tandaiSalinan($artikel, $induk);

            return false;
        }

        // Dedup lewat tanpa temuan, jadi artikel ini asli dan siap dinilai.
        //
        // Rantai job berhenti di sini dengan sengaja. Klasifikasi Gemini
        // dijalankan lewat tombol di halaman Antrean Klasifikasi, satu artikel
        // satu klik, sampai alurnya terbukti cukup stabil untuk dilepas ke
        // latar belakang. Artikel menunggu dengan status `isi_diambil`.
        return true;
    }
}
