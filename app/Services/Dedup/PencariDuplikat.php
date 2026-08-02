<?php

namespace App\Services\Dedup;

use App\Enums\StatusDedup;
use App\Models\Artikel;

/**
 * Deduplikasi lapis 1 dan 2. Lapis 3 (kemiripan makna lewat pgvector) menyusul
 * di sprint 3, setelah layanan embedding ada.
 *
 * Dedup dijalankan sebelum analisis supaya biaya inferensi tidak dibayar
 * sepuluh kali untuk rilis yang sama.
 */
class PencariDuplikat
{
    public function __construct(private PenghitungSimhash $simhash) {}

    /** Hash isi yang sudah dinormalisasi, untuk pencocokan persis. */
    public function hashIsi(string $isi): string
    {
        return hash('sha256', preg_replace('/\s+/u', ' ', mb_strtolower(trim($isi))));
    }

    /**
     * Artikel asli yang menjadi induk, atau null kalau ini yang pertama.
     *
     * Urutannya sengaja: hash persis dulu karena murah dan pasti, simhash
     * belakangan karena harus membandingkan banyak baris.
     */
    public function cariInduk(Artikel $artikel): ?Artikel
    {
        if ($artikel->hash_isi !== null) {
            $kembar = $this->query($artikel)
                ->where('hash_isi', $artikel->hash_isi)
                ->first();

            if ($kembar !== null) {
                return $kembar;
            }
        }

        if ($artikel->simhash === null) {
            return null;
        }

        return $this->cariLewatSimhash($artikel);
    }

    /**
     * Menandai artikel sebagai salinan dan menautkannya ke induk.
     *
     * Rantai duplikat dijaga maksimal satu tingkat: kalau C mirip B yang sudah
     * salinan dari A, C menunjuk langsung ke A.
     */
    public function tandaiSalinan(Artikel $artikel, Artikel $induk, ?float $skorKemiripan = null): void
    {
        $induk = $induk->artikel_induk_id !== null
            ? $induk->induk()->first() ?? $induk
            : $induk;

        $artikel->update([
            'status_dedup' => StatusDedup::Salinan,
            'artikel_induk_id' => $induk->id,
            'skor_kemiripan' => $skorKemiripan,
        ]);
    }

    /**
     * ponytail: perbandingan simhash dilakukan di PHP atas jendela tujuh hari.
     * Pada 300 artikel per hari itu sekitar 2.100 baris — tidak terasa. Kalau
     * volumenya naik jauh, pindahkan ke index LSH atau ekstensi bktree.
     */
    private function cariLewatSimhash(Artikel $artikel): ?Artikel
    {
        $kandidat = $this->query($artikel)
            ->whereNotNull('simhash')
            ->get(['id', 'simhash']);

        $terdekat = null;
        $jarakTerdekat = PHP_INT_MAX;

        foreach ($kandidat as $baris) {
            $jarak = $this->simhash->jarak($artikel->simhash, (int) $baris->simhash);

            if ($jarak < $jarakTerdekat) {
                $jarakTerdekat = $jarak;
                $terdekat = $baris;
            }
        }

        if ($terdekat === null || $jarakTerdekat > (int) config('crawler.dedup.ambang_simhash')) {
            return null;
        }

        return Artikel::withoutGlobalScopes()->find($terdekat->id);
    }

    /**
     * Kandidat induk: artikel asli dalam jendela waktu, selain dirinya sendiri.
     *
     * Global scope dilepas dengan sengaja. Deduplikasi justru harus melihat
     * lintas media — satu rilis yang dimuat sepuluh media adalah kasus utamanya.
     * Pemanggilnya selalu job latar, bukan request pengguna.
     */
    private function query(Artikel $artikel): \Illuminate\Database\Eloquent\Builder
    {
        return Artikel::withoutGlobalScopes()
            ->where('id', '!=', $artikel->id)
            ->where('status_dedup', StatusDedup::Asli)
            ->where('diambil_at', '>', now()->subDays((int) config('crawler.dedup.jendela_hari')))
            // `id` sebagai pemecah seri wajib: tiga worker crawl bisa menyimpan
            // beberapa salinan pada detik yang sama, dan tanpa urutan pasti
            // induknya berpindah-pindah antar-jalannya crawl.
            ->orderBy('diambil_at')
            ->orderBy('id');
    }
}
