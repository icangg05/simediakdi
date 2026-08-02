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

    /**
     * Pengetat setelah model relevansi menjawab ya.
     *
     * Model menganggap artikel yang menyebut konteks sekali lewat sebagai
     * relevan, sedangkan panduan pelabelan menghitungnya tidak relevan —
     * penyebutan bukan pembahasan. Selisih definisi itu membuat presisi
     * penyaring hanya 46,6% pada gold set, artinya separuh artikel di dashboard
     * sebenarnya tidak membahas konteksnya.
     *
     * Aturan ini diukur terhadap 254 label manusia, dengan separuh data ditahan
     * dan tidak pernah dilihat saat memilih varian:
     *
     * | Aturan                  | Presisi | Recall | F1    |
     * |-------------------------|---------|--------|-------|
     * | model apa adanya        | 54,2%   | 100%   | 0,703 |
     * | judul ATAU >=3x di isi  | 80,0%   | 92,3%  | 0,857 |
     *
     * Varian ">=4x" terlihat lebih baik saat memilih tapi lebih buruk pada data
     * tahan — jangan menaikkannya tanpa mengukur ulang.
     *
     * Konteks tanpa kata kunci tidak diketatkan: tidak ada yang bisa dihitung,
     * dan menolak semuanya akan mematikan konteks itu diam-diam.
     */
    public function menonjol(string $judul, ?string $isi, KonteksPantauan $konteks): bool
    {
        $kataKunci = $konteks->kata_kunci ?? [];

        if ($kataKunci === []) {
            return true;
        }

        if ($this->hitung($judul, $kataKunci) > 0) {
            return true;
        }

        return $this->hitung((string) $isi, $kataKunci) >= (int) config('nlp.minimal_sebutan', 3);
    }

    /** @param list<string> $kataKunci */
    private function hitung(string $teks, array $kataKunci): int
    {
        $teks = $this->normalkan($teks);
        $jumlah = 0;

        foreach ($kataKunci as $kata) {
            $jumlah += substr_count($teks, $this->normalkan((string) $kata));
        }

        return $jumlah;
    }

    /** Huruf kecil dan spasi tunggal, supaya "Wali  Kota" cocok dengan "wali kota". */
    private function normalkan(string $teks): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($teks)));
    }
}
