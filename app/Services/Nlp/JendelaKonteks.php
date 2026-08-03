<?php

namespace App\Services\Nlp;

use App\Models\Artikel;
use App\Models\KonteksPantauan;

/**
 * Teks terfokus yang mewakili artikel saat menilai relevansi.
 *
 * Mengirim isi artikel apa adanya dari huruf pertama adalah kesalahan yang
 * mahal: model embedding punya batas token, sedangkan kalimat yang benar-benar
 * menjelaskan hubungan dengan Pemkot sering berada di paragraf keenam. Yang
 * terpotong justru bagian yang menentukan.
 *
 * Jadi yang dikirim adalah judul, ringkasan, dan potongan kalimat di sekitar
 * tiap sebutan konteks. Dokumen 05 bagian 3.2.
 *
 * Efek sampingnya harus diingat saat membaca skor: karena teks ini disusun
 * dari sekitar sebutan Pemkot, artikel yang menyebut Pemkot sekali sepintas
 * pun menghasilkan teks yang isinya hampir seluruhnya tentang Pemkot, lalu
 * mendapat skor tinggi. Itu sebabnya pengetat frekuensi sebutan di
 * PenyaringKataKunci::menonjol() tetap dipasang sesudah ambang.
 */
class JendelaKonteks
{
    /** Kalimat yang diambil di kiri dan kanan tiap sebutan. */
    private const LEBAR = 2;

    /** Batas aman di bawah 512 token model, dihitung kasar dari jumlah huruf. */
    private const MAKS_HURUF = 1800;

    public function bentuk(Artikel $artikel, KonteksPantauan $konteks): string
    {
        $bagian = ['Judul: '.$artikel->judul];

        if ($artikel->ringkasan !== null && $artikel->ringkasan !== '') {
            $bagian[] = 'Ringkasan: '.$artikel->ringkasan;
        }

        $potongan = $this->potongan((string) $artikel->isi, $konteks);

        if ($potongan !== '') {
            $bagian[] = 'Potongan isi terkait: '.$potongan;
        }

        return implode("\n", $bagian);
    }

    /**
     * Kalimat di sekitar tiap sebutan kata kunci konteks.
     *
     * Kalau konteks tidak punya kata kunci, atau tidak satu pun kata kuncinya
     * muncul, jatuh kembali ke bagian awal isi. Mengembalikan teks kosong akan
     * membuat artikel dinilai hanya dari judulnya, dan judul berita lokal
     * sering tidak menyebut pelakunya sama sekali.
     */
    private function potongan(string $isi, KonteksPantauan $konteks): string
    {
        $kataKunci = array_map($this->normalkan(...), $konteks->kata_kunci ?? []);

        if ($isi === '') {
            return '';
        }

        $kalimat = preg_split('/(?<=[.!?])\s+/u', $isi, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($kataKunci === [] || $kalimat === []) {
            return mb_substr($isi, 0, self::MAKS_HURUF);
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

        if ($pilih === []) {
            return mb_substr($isi, 0, self::MAKS_HURUF);
        }

        // Diurutkan kembali menurut posisi aslinya: potongan yang melompat
        // mundur membuat teks terbaca seperti kalimat acak, dan model
        // membandingkan makna, bukan daftar kata.
        ksort($pilih);

        return mb_substr(implode(' ', $pilih), 0, self::MAKS_HURUF);
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
