<?php

namespace Tests\Unit;

use App\Services\Crawler\PembersihHtml;
use PHPUnit\Framework\TestCase;

/**
 * Isi artikel disimpan sebagai teks rapi, bukan sebagai jejak tata letak HTML.
 *
 * Spasi berlebih bukan sekadar jelek dipandang. Ia ikut terhitung sebagai token
 * yang dibayar saat artikel dikirim ke Gemini, ikut memenuhi kolom ekspor, dan
 * membuat pencarian frasa meleset karena kata dipisah dua spasi.
 */
class PembersihHtmlTest extends TestCase
{
    private PembersihHtml $bersih;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bersih = new PembersihHtml;
    }

    public function test_spasi_nbsp_dan_tab_dipadatkan_jadi_satu(): void
    {
        $hasil = $this->bersih->keTeks('<p>Wali&nbsp;&nbsp;Kota   Kendari		meninjau</p>');

        $this->assertSame('Wali Kota Kendari meninjau', $hasil);
    }

    public function test_baris_kosong_berlebih_dipangkas_jadi_satu(): void
    {
        $html = "<p>Paragraf satu</p>\n \n\n  \n<p>Paragraf dua</p>";

        $this->assertSame("Paragraf satu\n\nParagraf dua", $this->bersih->keTeks($html));
    }

    /** Halaman lama masih mengirim CRLF; sisa `\r` terbaca sebagai sampah. */
    public function test_crlf_diseragamkan_dan_ujung_baris_dirapikan(): void
    {
        $hasil = $this->bersih->keTeks("<p>Satu   \r\n   dua</p>");

        $this->assertSame("Satu\ndua", $hasil);
        $this->assertStringNotContainsString("\r", $hasil);
    }

    public function test_rapikan_ikut_membuang_nbsp(): void
    {
        $this->assertSame('Judul berita', $this->bersih->rapikan("Judul&nbsp;\n  berita "));
    }

    public function test_hitung_kata_tidak_terkecoh_spasi_ganda(): void
    {
        $this->assertSame(3, $this->bersih->hitungKata($this->bersih->keTeks('<p>satu  dua&nbsp;&nbsp;tiga</p>')));
    }
}
