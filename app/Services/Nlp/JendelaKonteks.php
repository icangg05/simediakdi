<?php

namespace App\Services\Nlp;

/**
 * Kalimat di sekitar tiap sebutan kata kunci konteks.
 *
 * Mengirim isi artikel apa adanya dari huruf pertama adalah kesalahan yang
 * mahal: model dipotong di batas token, sedangkan kalimat yang benar-benar
 * menjelaskan hubungan dengan Pemkot sering berada di paragraf keenam. Yang
 * terpotong justru bagian yang menentukan.
 *
 * Sejak revisi 1.6 kelas ini menerima teks biasa, bukan model Eloquent.
 * Pemakainya tiga: sampel dataset, artikel produksi, dan teks manual di tab
 * Uji Model. Menerima `Artikel` membuat dua di antaranya harus mengarang
 * objek palsu hanya untuk memanggilnya.
 *
 * Efek sampingnya harus diingat: karena teks ini disusun dari sekitar sebutan
 * Pemkot, artikel yang menyebut Pemkot sekali sepintas pun menghasilkan teks
 * yang isinya hampir seluruhnya tentang Pemkot. Model harus belajar
 * membedakannya dari contoh, dan itulah gunanya hard negative di dataset.
 */
class JendelaKonteks
{
    /** Kalimat yang diambil di kiri dan kanan tiap sebutan. */
    private const LEBAR = 2;

    /**
     * @param  list<string>  $kataKunci
     */
    public function potongan(string $isi, array $kataKunci, int $maksHuruf): string
    {
        if ($isi === '') {
            return '';
        }

        $kataKunci = array_values(array_filter(array_map($this->normalkan(...), $kataKunci)));
        $kalimat = preg_split('/(?<=[.!?])\s+/u', $isi, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($kataKunci === [] || $kalimat === []) {
            return mb_substr($isi, 0, $maksHuruf);
        }

        $pilih = [];

        foreach ($kalimat as $i => $satu) {
            if (! $this->memuat($satu, $kataKunci)) {
                continue;
            }

            for ($j = $i - self::LEBAR; $j <= $i + self::LEBAR; $j++) {
                if (isset($kalimat[$j])) {
                    $pilih[$j] = $kalimat[$j];
                }
            }
        }

        // Tidak satu pun kata kunci muncul. Jatuh ke bagian awal isi, bukan
        // teks kosong: artikel yang dinilai hanya dari judulnya akan salah
        // dinilai, karena judul berita lokal sering tidak menyebut pelakunya.
        if ($pilih === []) {
            return mb_substr($isi, 0, $maksHuruf);
        }

        // Diurutkan kembali menurut posisi aslinya. Potongan yang melompat
        // mundur terbaca seperti kalimat acak, dan model membaca urutan.
        ksort($pilih);

        return mb_substr(implode(' ', $pilih), 0, $maksHuruf);
    }

    /** @param list<string> $kataKunci */
    private function memuat(string $kalimat, array $kataKunci): bool
    {
        $kalimat = $this->normalkan($kalimat);

        foreach ($kataKunci as $kata) {
            if ($kata !== '' && str_contains($kalimat, $kata)) {
                return true;
            }
        }

        return false;
    }

    private function normalkan(string $teks): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($teks)));
    }
}
