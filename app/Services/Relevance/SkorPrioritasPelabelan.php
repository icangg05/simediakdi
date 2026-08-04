<?php

namespace App\Services\Relevance;

use App\Models\Artikel;
use App\Models\KonteksPantauan;

/**
 * Urutan antrean pelabelan, beserta alasan urutannya.
 *
 * Waktu pelabel adalah sumber daya paling langka di proyek ini, satu-satunya
 * yang tidak bisa dipercepat dengan server lebih besar. Yang menentukan
 * berhasil tidaknya laboratorium bukan berapa banyak artikel yang dilabeli,
 * melainkan artikel mana.
 *
 * Komponennya disimpan bersama totalnya. Antrean prioritas yang tidak bisa
 * ditanya alasannya akan diabaikan pelabel pada hari ketiga, dan antrean yang
 * diabaikan sama saja dengan tidak ada.
 *
 * Versi fase 1 memakai sinyal kata kunci saja. Sembilan komponen lain di
 * dokumen 10 bagian 8, mulai dari kedekatan dengan ambang sampai perbedaan
 * pendapat antar-model, semuanya membutuhkan prediksi model yang belum ada.
 * Menambahkannya sekarang berarti menulis rumus yang seluruh sukunya nol.
 *
 * ponytail: skor dari sinyal kata kunci saja, tambahkan komponen berbasis
 * prediksi begitu model pertama lolos fase 3.
 */
class SkorPrioritasPelabelan
{
    /**
     * Bobot sinyal, dokumen 05 bagian 4.
     *
     * Yang dikejar bukan artikel yang paling mungkin relevan, melainkan
     * artikel yang jawabannya paling tidak jelas. Judul yang menyebut Pemkot
     * terang-terangan justru bernilai rendah di sini: pelabel bisa
     * memutuskannya dalam dua detik, dan model juga.
     */
    private const BOBOT_KABUR = 30;

    private const BOBOT_SEBUTAN_TIPIS = 25;

    private const BOBOT_KONTRAS = 20;

    /**
     * Istilah yang paling sering menghasilkan false positive, dokumen 05
     * bagian 6.2. Artikel yang memuat salah satunya bersama sebutan Pemkot
     * adalah kasus batas yang harus dilihat manusia.
     */
    private const KONTRAS = [
        'pemerintah provinsi', 'pemprov', 'gubernur', 'polda', 'polres', 'polsek',
        'kapolda', 'kapolres', 'tni', 'kodim', 'korem', 'kejaksaan', 'kejari',
        'pengadilan', 'bea cukai', 'bps', 'kanwil', 'kementerian', 'universitas',
        'kabupaten',
    ];

    /**
     * @return array{total: int, komponen: array<string, int>}
     */
    public function hitung(Artikel $artikel): array
    {
        $konteks = KonteksPantauan::utama();

        if ($konteks === null) {
            return ['total' => 0, 'komponen' => []];
        }

        $judul = $this->normalkan($artikel->judul);
        $isi = $this->normalkan((string) $artikel->isi);
        $kataKunci = array_map(fn ($k) => $this->normalkan((string) $k), $konteks->kata_kunci ?? []);

        $diJudul = $this->hitungSebutan($judul, $kataKunci);
        $diIsi = $this->hitungSebutan($isi, $kataKunci);

        $komponen = [];

        // Tidak disebut di judul tapi dibahas panjang di isi. Inilah pola
        // artikel kritik yang judulnya tidak menyebut Pemkot, salah satu jenis
        // false negative yang paling merugikan.
        if ($diJudul === 0 && $diIsi >= 3) {
            $komponen['kabur_judul_bersih'] = self::BOBOT_KABUR;
        }

        // Disebut sekali lalu tidak lagi. Hampir selalu tidak relevan menurut
        // panduan, dan hampir selalu dianggap relevan oleh sistem yang menilai
        // dari potongan kalimat di sekitar sebutan.
        if ($diJudul === 0 && $diIsi === 1) {
            $komponen['sebutan_tipis'] = self::BOBOT_SEBUTAN_TIPIS;
        }

        if ($diIsi > 0 && $this->memuatKontras($judul.' '.$isi)) {
            $komponen['pola_kontras'] = self::BOBOT_KONTRAS;
        }

        // Komponen kelima, tag menyebut Pemkot padahal isinya tidak, belum bisa
        // dihitung. Kolom `kategori_sumber` dan `tag_sumber` belum ada di tabel
        // artikel dan crawler belum memanennya, jadi sukunya akan selalu nol.
        // Sprint 6 fase 2 yang membukanya.
        //
        // ponytail: empat komponen dari sinyal kata kunci, tambahkan komponen
        // berbasis tag setelah sprint 6 fase 2 dan komponen berbasis prediksi
        // setelah model pertama lolos fase 3.

        return ['total' => array_sum($komponen), 'komponen' => $komponen];
    }

    /** @param list<string> $kataKunci */
    private function hitungSebutan(string $teks, array $kataKunci): int
    {
        $jumlah = 0;

        foreach ($kataKunci as $kata) {
            if ($kata !== '') {
                $jumlah += substr_count($teks, $kata);
            }
        }

        return $jumlah;
    }

    private function memuatKontras(string $teks): bool
    {
        foreach (self::KONTRAS as $istilah) {
            if (str_contains($teks, $istilah)) {
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
