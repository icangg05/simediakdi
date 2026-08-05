<?php

namespace App\Services\Relevance;

use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Models\SampelRelevansi;

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
 * Dua tingkat, dan bedanya besar. Tanpa model, yang bisa dihitung hanya sinyal
 * kata kunci, dan ribuan sampel berakhir berskor nol tanpa bisa dibedakan satu
 * sama lain. Dengan prediksi, antrean bisa menunjuk tempat model sendiri ragu,
 * dan di situlah satu jam pelabelan bernilai paling banyak.
 *
 * ponytail: komponen perbedaan pendapat antar-model belum ada, ia baru berarti
 * setelah ada dua versi model yang bisa dibandingkan.
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

    /** Model paling ragu di sekitar 0,5. Di situlah pelabelan paling berguna. */
    private const BOBOT_DEKAT_AMBANG = 40;

    /** Model yakin tetapi berlawanan dengan sinyal kata kunci. */
    private const BOBOT_BERTENTANGAN = 35;

    /**
     * Skor prioritas satu sampel setelah modelnya memberi prediksi.
     *
     * Inilah yang menyalakan active learning. Sebelum ada model, antrean hanya
     * punya empat sinyal kata kunci dan 2.312 sampel berskor nol yang tidak
     * bisa dibedakan satu sama lain. Prediksi menambahkan hal yang tidak bisa
     * ditebak aturan: di mana model sendiri ragu.
     *
     * Yang dikejar bukan artikel yang paling mungkin relevan, melainkan yang
     * paling banyak mengajari. Artikel berkeyakinan 0,99 tidak menambah apa pun
     * bagi model, sedangkan artikel di 0,52 adalah tempat batas keputusannya
     * masih kabur.
     *
     * @param  array<string, mixed>  $prediksi
     * @return array{priority_score: int, priority_reasons: array<string, int>}
     */
    public function hitungDenganPrediksi(SampelRelevansi $sampel, array $prediksi): array
    {
        $komponen = $this->dariTeks($sampel->judul, (string) $sampel->isi);

        $peluang = (float) $prediksi['probabilitas_relevan'];

        // Jarak dari 0,5, dibalik lalu diskalakan. Peluang 0,50 memberi bobot
        // penuh, 0,99 hampir nol.
        $kedekatan = 1 - abs($peluang - 0.5) * 2;

        if ($kedekatan > 0.2) {
            $komponen['dekat_ambang'] = (int) round(self::BOBOT_DEKAT_AMBANG * $kedekatan);
        }

        // Model yakin tidak relevan padahal kata kuncinya menonjol, atau
        // sebaliknya. Salah satu dari keduanya pasti keliru, dan menemukan yang
        // mana adalah pekerjaan yang paling berharga untuk pelabel.
        $sinyalKuat = isset($komponen['kabur_judul_bersih']) || ! isset($komponen['sebutan_tipis']);

        if ($peluang < 0.2 && $sinyalKuat && isset($komponen['kabur_judul_bersih'])) {
            $komponen['bertentangan_dengan_sinyal'] = self::BOBOT_BERTENTANGAN;
        }

        if ($peluang > 0.8 && isset($komponen['sebutan_tipis'])) {
            $komponen['bertentangan_dengan_sinyal'] = self::BOBOT_BERTENTANGAN;
        }

        // Komponen `input_terpotong` sempat ada di sini lalu dibuang. Ia
        // menyala pada 2.746 dari 4.137 sampel, dua pertiga korpus, dan
        // komponen yang menyala hampir di mana-mana tidak membedakan apa pun:
        // ia hanya menggeser seluruh skor ke atas secara merata lalu
        // menenggelamkan `dekat_ambang` yang menyala 150 kali dan justru
        // paling berharga.
        //
        // Pemotongannya sendiri tetap masalah dan tercatat di `input_truncated`
        // milik tiap prediksi, tempat yang benar untuk menyelidikinya.

        return [
            'priority_score' => array_sum($komponen),
            'priority_reasons' => $komponen,
        ];
    }

    /**
     * @return array{total: int, komponen: array<string, int>}
     */
    public function hitung(Artikel $artikel): array
    {
        $komponen = $this->dariTeks($artikel->judul, (string) $artikel->isi);

        return ['total' => array_sum($komponen), 'komponen' => $komponen];
    }

    /**
     * Sinyal kata kunci, satu-satunya yang bisa dihitung tanpa model.
     *
     * Dipakai bersama artikel produksi dan sampel dataset. Menyalinnya ke dua
     * tempat berarti antrean pelabelan dan antrean impor lambat laun mengurut
     * dengan aturan yang berbeda, dan tidak ada yang akan menyadarinya.
     *
     * @return array<string, int>
     */
    private function dariTeks(string $judulAsli, string $isiAsli): array
    {
        $konteks = KonteksPantauan::utama();

        if ($konteks === null) {
            return [];
        }

        $judul = $this->normalkan($judulAsli);
        $isi = $this->normalkan($isiAsli);
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

        // Komponen berbasis tag belum bisa dihitung: kolom `kategori_sumber`
        // dan `tag_sumber` belum ada di tabel artikel dan crawler belum
        // memanennya. Sprint 6 fase 2 yang membukanya.
        //
        // ponytail: tiga komponen dari kata kunci, tambahkan komponen berbasis
        // tag setelah sprint 6 fase 2.

        return $komponen;
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
