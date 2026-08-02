<?php

namespace Tests\Unit;

use App\Services\Crawler\NormalisasiUrl;
use PHPUnit\Framework\TestCase;

/** Deduplikasi lapis 1 seluruhnya bergantung pada fungsi ini. */
class NormalisasiUrlTest extends TestCase
{
    private NormalisasiUrl $normalisasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalisasi = new NormalisasiUrl;
    }

    public function test_varian_url_yang_sama_menghasilkan_satu_bentuk_kanonik(): void
    {
        $harapan = 'https://kendaripos.fajar.co.id/berita/drainase-kadia';

        $varian = [
            'https://kendaripos.fajar.co.id/berita/drainase-kadia',
            'https://www.kendaripos.fajar.co.id/berita/drainase-kadia/',
            'https://KendariPos.Fajar.co.id/berita/drainase-kadia',
            'https://kendaripos.fajar.co.id/berita/drainase-kadia?utm_source=facebook&utm_medium=social',
            'https://kendaripos.fajar.co.id/berita/drainase-kadia#komentar',
            'https://kendaripos.fajar.co.id//berita//drainase-kadia',
            'https://kendaripos.fajar.co.id/berita/drainase-kadia/amp',
            'https://kendaripos.fajar.co.id:443/berita/drainase-kadia',
        ];

        foreach ($varian as $url) {
            $this->assertSame($harapan, $this->normalisasi->kanonik($url), "Gagal pada {$url}");
        }
    }

    public function test_parameter_identitas_halaman_tidak_ikut_dibuang(): void
    {
        // Banyak situs PHP memakai ?p=123 sebagai identitas halaman; membuangnya
        // akan menggabungkan artikel yang sebenarnya berbeda.
        $this->assertSame(
            'https://contoh.id/index.php?p=123',
            $this->normalisasi->kanonik('https://contoh.id/index.php?p=123&utm_campaign=x'),
        );
    }

    public function test_urutan_parameter_tidak_membuat_url_berbeda(): void
    {
        $this->assertSame(
            $this->normalisasi->kanonik('https://contoh.id/a?b=2&a=1'),
            $this->normalisasi->kanonik('https://contoh.id/a?a=1&b=2'),
        );
    }

    public function test_domain_dibaca_tanpa_www(): void
    {
        $this->assertSame('telisik.id', $this->normalisasi->domain('https://www.telisik.id/berita/1'));
        $this->assertSame(
            'kendaripos.fajar.co.id',
            $this->normalisasi->domain('https://kendaripos.fajar.co.id/berita/1'),
        );
    }

    public function test_tautan_relatif_dijadikan_absolut(): void
    {
        $this->assertSame(
            'https://contoh.id/berita/1',
            $this->normalisasi->absolutkan('/berita/1', 'https://contoh.id/kategori/daerah'),
        );

        $this->assertSame(
            'https://contoh.id/kategori/berita/1',
            $this->normalisasi->absolutkan('berita/1', 'https://contoh.id/kategori/daerah'),
        );

        $this->assertSame(
            'https://cdn.contoh.id/gambar.jpg',
            $this->normalisasi->absolutkan('//cdn.contoh.id/gambar.jpg', 'https://contoh.id/a'),
        );
    }
}
