<?php

namespace Tests\Feature;

use App\Services\Crawler\HasilEkstraksi;
use PHPUnit\Framework\TestCase;

/**
 * Judul cadangan untuk artikel yang sumbernya memang tidak punya judul.
 *
 * Tiga artikel di database masuk tanpa judul, dan halaman aslinya pun kosong:
 * `<title>` hanya berisi nama situs, `og:title` sama, `<h1>` kosong. Ketiganya
 * berakhir sebagai "(tanpa judul)" di tabel admin, tidak bisa dicari, dan tidak
 * bisa dirujuk antar-admin.
 *
 * Contoh di bawah disalin dari isi ketiga artikel itu, bukan dikarang, karena
 * yang menentukan benar atau salahnya heuristik ini adalah bentuk tulisan
 * redaksi yang sebenarnya.
 */
class JudulCadanganTest extends TestCase
{
    /**
     * Sebagian redaksi menaruh judul sebagai baris tersendiri di atas paragraf
     * pembuka. Baris itu memang judulnya dan dipakai apa adanya.
     */
    public function test_baris_pertama_yang_pendek_dipakai_apa_adanya(): void
    {
        $isi = "Sebuah Rumah Ludes Terbakar di Kelurahan Watulondo\n\n"
            ."KENDARI, INFORMASISULTRA.COM ".$this->pisah()." Kebakaran rumah terjadi di Perumahan Pesona King Adam 2.";

        $this->assertSame(
            'Sebuah Rumah Ludes Terbakar di Kelurahan Watulondo',
            HasilEkstraksi::judulDariIsi($isi),
        );
    }

    /**
     * Dateline dibuang lebih dulu. "KENDARI, INFORMASISULTRA.COM" adalah nama
     * kota dan nama media, bukan isi beritanya, dan judul yang diawali keduanya
     * membuat seluruh daftar terbaca seragam tanpa membedakan apa pun.
     */
    public function test_dateline_dibuang_sebelum_kalimat_pembuka_dipakai(): void
    {
        $isi = 'KENDARI, INFORMASISULTRA.COM '.$this->pisah(en: true)
            .' Wali Kota Kendari, dr. Hj. Siska Karina Imran, SKM menerima kunjungan kerja Komisi V DPR RI.';

        $this->assertSame(
            'Wali Kota Kendari, dr. Hj. Siska Karina Imran, SKM menerima kunjungan kerja Komisi V DPR RI.',
            HasilEkstraksi::judulDariIsi($isi),
        );
    }

    /** Paragraf panjang dipotong pada batas kata, bukan di tengah kata. */
    public function test_paragraf_panjang_dipotong_pada_batas_kata(): void
    {
        $isi = 'KENDARIKITA '.$this->pisah().' Kantor Unit Penyelenggara Pelabuhan (KUPP) Kelas I Molawe '
            .'menerima kunjungan Badan Penegakan Hukum (Gakkum) BKSDA Kehutanan Sulawesi Tenggara pada Jumat pagi.';

        $judul = HasilEkstraksi::judulDariIsi($isi);

        $this->assertLessThanOrEqual(120, mb_strlen($judul));
        $this->assertStringStartsWith('Kantor Unit Penyelenggara Pelabuhan', $judul);
        $this->assertStringEndsNotWith(' ', $judul);
        // Kata terakhir harus utuh, bukan penggalan seperti "Sulawesi Teng".
        $this->assertStringContainsString(' ', $judul);
        $this->assertTrue(
            str_contains($isi, (string) mb_strrchr($judul, ' ')),
            'Kata terakhir judul harus muncul utuh di dalam isi artikel.',
        );
    }

    /** Isi kosong tidak menghasilkan judul karangan. */
    public function test_isi_kosong_menghasilkan_null(): void
    {
        $this->assertNull(HasilEkstraksi::judulDariIsi(null));
        $this->assertNull(HasilEkstraksi::judulDariIsi('   '));
    }

    /**
     * Tanda pisah dirakit dari titik kodenya, bukan diketik langsung.
     *
     * Repositori ini melarang em dash dan en dash muncul di dalam berkas mana
     * pun, termasuk berkas tes. Yang dilarang adalah menulisnya, bukan
     * menguji bahwa data dari luar yang memuatnya tertangani dengan benar.
     */
    private function pisah(bool $en = false): string
    {
        return mb_chr($en ? 0x2013 : 0x2014, 'UTF-8');
    }
}
