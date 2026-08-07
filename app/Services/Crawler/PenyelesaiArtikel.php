<?php

namespace App\Services\Crawler;

use App\Models\AntreanGemini;
use App\Models\Artikel;
use Illuminate\Support\Facades\Log;

/**
 * Langkah setelah isi artikel tersedia: simpan isinya, lalu antre untuk dinilai.
 *
 * Dipakai dua jalur, pengambilan halaman satu per satu (AmbilIsiArtikel) dan
 * penarikan arsip massal (crawl:backfill), supaya keduanya menghasilkan baris
 * yang bentuknya sama.
 */
class PenyelesaiArtikel
{
    /**
     * @return bool true kalau artikel masuk antrean analisis, false kalau
     *              berhenti di sini karena isinya kosong
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

        $artikel->save();

        if ($hasil->isi === null || $hasil->isi === '') {
            // Ekstraksi kosong bukan kegagalan fatal: judul dan URL sudah cukup
            // untuk pencocokan pemuatan kontrak. Ditandai supaya bisa diaudit.
            Log::warning('Ekstraksi isi kosong', ['artikel_id' => $artikel->id, 'url' => $artikel->url]);
            $artikel->update(['pesan_gagal' => 'Isi artikel tidak dapat diekstrak dari halaman.']);

            return false;
        }

        // Artikel berstatus `isi_diambil`, yang di layar berjudul "Belum
        // diklasifikasi", dan langsung mengantre penilaian relevansi.
        //
        // Dicatat di sini, bukan ditunggu penyisiran `gemini:antre --isi` yang
        // berjalan sejam sekali. Penyisiran itu tetap ada sebagai jaring
        // pengaman untuk artikel lama, tetapi mengandalkannya berarti artikel
        // yang selesai diekstrak pada detik ke-61 menganggur hampir satu jam
        // penuh sebelum ada yang menyentuhnya.
        //
        // `insertOrIgnore`, bukan `insert`. Artikel yang diekstrak ulang sudah
        // punya barisnya sendiri, dan kunci unik pada `artikel_id` akan menolak
        // seluruh perintah kalau ditabrak.
        AntreanGemini::insertOrIgnore([
            'artikel_id' => $artikel->id,
            'prioritas' => 1,
            'status' => 'menunggu',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }
}
