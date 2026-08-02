<?php

namespace App\Services\Nlp;

use App\Models\KonteksPantauan;

/**
 * Saringan murah sebelum model relevansi dipanggil.
 *
 * Menambah konteks menaikkan beban inferensi secara linear: delapan konteks
 * berarti delapan panggilan relevansi per artikel. Penyaring inilah yang
 * membuat penambahan konteks tetap terjangkau — berita tentang harga cabai
 * tidak perlu ditanyakan ke model apakah relevan dengan "Wali Kota Kendari".
 *
 * Sengaja longgar. Tugasnya membuang yang jelas tidak nyambung, bukan
 * memutuskan relevansi — itu pekerjaan model. Konteks tanpa kata kunci selalu
 * diteruskan.
 */
class PenyaringKataKunci
{
    public function lolos(string $teks, KonteksPantauan $konteks): bool
    {
        $kataKunci = $konteks->kata_kunci ?? [];

        if ($kataKunci === []) {
            return true;
        }

        $teks = $this->normalkan($teks);

        foreach ($kataKunci as $kata) {
            if (str_contains($teks, $this->normalkan((string) $kata))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<KonteksPantauan>  $daftar
     * @return list<KonteksPantauan>
     */
    public function saring(string $teks, iterable $daftar): array
    {
        $lolos = [];

        foreach ($daftar as $konteks) {
            if ($this->lolos($teks, $konteks)) {
                $lolos[] = $konteks;
            }
        }

        return $lolos;
    }

    /** Huruf kecil dan spasi tunggal, supaya "Wali  Kota" cocok dengan "wali kota". */
    private function normalkan(string $teks): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($teks)));
    }
}
