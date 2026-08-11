<?php

namespace Tests\Unit;

use App\Services\Crawler\EkstraktorArtikel;
use App\Services\Crawler\PembersihHtml;
use PHPUnit\Framework\TestCase;

/**
 * Tanggal terbit menentukan artikel jatuh ke hari yang mana di seluruh grafik,
 * karena agregasi memakai `coalesce(dipublikasikan_at, diambil_at)`. Kalau
 * ekstraksinya kosong, berita tiga tahun lalu ikut terhitung sebagai berita
 * hari ini. Sumber hasil pencarian membuat itu bukan kasus langka lagi.
 */
class EkstraktorArtikelTest extends TestCase
{
    private EkstraktorArtikel $ekstraktor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ekstraktor = new EkstraktorArtikel(new PembersihHtml);
    }

    private function halaman(string $kepala): string
    {
        return '<html><head>'.$kepala.'</head><body><article>'
            .str_repeat('<p>Pemkot Kendari menata kawasan pesisir bersama warga setempat.</p>', 12)
            .'</article></body></html>';
    }

    public function test_tanggal_dari_article_published_time(): void
    {
        $hasil = $this->ekstraktor->ekstrak(
            $this->halaman('<meta property="article:published_time" content="2026-08-07T20:23:15+07:00">'),
            'https://contoh.id/berita',
        );

        $this->assertSame('2026-08-07 13:23:15', $hasil->dipublikasikanAt?->toDateTimeString());
    }

    /**
     * Bentuk yang dipakai Detik. Meta `itemprop="datePublished"` miliknya
     * berisi "2026-08-07T20-23-15Z", dengan tanda hubung di posisi titik dua,
     * dan tidak bisa diurai. JSON-LD di halaman yang sama justru rapi.
     */
    public function test_tanggal_dari_json_ld_saat_meta_rusak(): void
    {
        $hasil = $this->ekstraktor->ekstrak(
            $this->halaman(
                '<meta name="pubdate" content="2026-08-07T20-23-15Z" itemprop="datePublished" />'
                .'<script type="application/ld+json">{"datePublished":"2026-08-07T20:23:15+07:00"}</script>',
            ),
            'https://contoh.id/berita',
        );

        $this->assertSame('2026-08-07 13:23:15', $hasil->dipublikasikanAt?->toDateTimeString());
    }

    public function test_halaman_tanpa_tanggal_menghasilkan_null(): void
    {
        $hasil = $this->ekstraktor->ekstrak($this->halaman('<title>Berita</title>'), 'https://contoh.id/berita');

        $this->assertNull($hasil->dipublikasikanAt);
    }
}
