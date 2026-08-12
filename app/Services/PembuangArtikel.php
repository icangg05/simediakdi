<?php

namespace App\Services;

use App\Models\Artikel;
use App\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Satu-satunya pintu penghapusan artikel.
 *
 * Penghapusan bersifat permanen. Tidak ada nisan URL, tidak ada tempat sampah,
 * dan tidak ada daftar pengecualian. Konsekuensinya diterima: URL yang dihapus
 * bisa masuk lagi pada crawl berikutnya, karena satu-satunya penjaga duplikat
 * sekarang adalah baris yang masih ada di tabel artikel.
 *
 * Database menjaga sisanya. Foreign key dari analisis_sentimen dan
 * antrean_gemini memakai CASCADE, jadi barisnya ikut terhapus tanpa perlu
 * disebut di sini.
 */
class PembuangArtikel
{
    /**
     * Artikel yang boleh dibuang dari halaman Antrean AI.
     *
     * Tidak relevan dan perlu review, dua kelompok yang sudah dinilai dan tidak
     * dipakai laporan apa pun. Artikel relevan tidak pernah masuk daftar ini,
     * berapa pun sentimennya, karena justru itu isi laporan. Itu satu-satunya
     * batas yang tersisa, dan di dalam batas itu tidak ada lagi pengecualian.
     */
    public static function kandidat(): Builder
    {
        return Artikel::query()->where(function (Builder $q) {
            $q->whereHas('analisisSentimen', fn (Builder $a) => $a->where('relevan', false))
                ->orWhere('status_proses', 'perlu_review');
        });
    }

    /**
     * @param  Collection<int, int>|list<int>  $id
     * @return array{dibuang: int, dilindungi: int}
     */
    public function buang(Collection|array $id, string $alasan): array
    {
        $diminta = collect($id)->map(fn ($satu): int => (int) $satu)->unique();

        if ($diminta->isEmpty()) {
            return ['dibuang' => 0, 'dilindungi' => 0];
        }

        $boleh = self::kandidat()->whereIn('id', $diminta)->pluck('id');

        $dibuang = 0;

        foreach ($boleh->chunk(500) as $bagian) {
            $dibuang += Artikel::withoutGlobalScopes()->whereIn('id', $bagian)->delete();
        }

        return [
            'dibuang' => $dibuang,
            'dilindungi' => $diminta->count() - $boleh->count(),
        ];
    }

    /**
     * Mencabut satu berita yang ditambahkan sendiri lewat portal media.
     *
     * Gerbangnya berbeda dari kandidat(), dan bedanya bukan kelalaian. Gerbang
     * admin melindungi artikel relevan karena artikel itulah isi laporan, dan
     * admin tidak pernah boleh membuangnya dari halaman Antrean AI. Portal
     * memakai gerbang lain: media boleh mencabut kirimannya sendiri, termasuk
     * yang sudah dinilai relevan dan sudah terhitung.
     *
     * Itu keputusan pemilik produk, diambil sadar pada 12 Agustus 2026 setelah
     * akibatnya disampaikan. Akibatnya perlu ditulis di sini supaya tidak hilang
     * bersama percakapan yang melahirkannya:
     *
     * Berita berlencana "Tampil" adalah bukti realisasi kontrak kerja sama
     * publikasi. Membiarkan media mencabutnya berarti pihak yang berkepentingan
     * atas angka realisasi punya jalan menyunting angka itu sendiri, dan
     * penghapusannya permanen sehingga tidak ada catatan bahwa barisnya pernah
     * ada. Selama belum ada audit log, satu-satunya jejak yang tersisa adalah
     * selisih antara angka realisasi kemarin dan angka realisasi hari ini.
     *
     * Yang tetap dijaga di sini ada dua, dan keduanya batas antar pihak, bukan
     * batas selera:
     *
     * 1. Hanya berita yang memang masuk lewat portal. `dilaporkan_oleh` kosong
     *    berarti crawler yang menemukannya, dan media tidak pernah boleh
     *    menghapus temuan sistem.
     * 2. Hanya berita milik medianya sendiri. Scope global MilikMedia sudah
     *    menjaganya pada route model binding, tetapi superadmin tidak terkena
     *    scope itu, dan pemeriksaan ini yang menutup celahnya.
     */
    public function buangKirimanPortal(Artikel $artikel, Media $media): bool
    {
        if ($artikel->dilaporkan_oleh === null || $artikel->media_id !== $media->id) {
            return false;
        }

        return (bool) Artikel::withoutGlobalScopes()->whereKey($artikel->id)->delete();
    }
}
