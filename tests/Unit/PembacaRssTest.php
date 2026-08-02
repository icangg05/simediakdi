<?php

namespace Tests\Unit;

use App\Services\Crawler\NormalisasiUrl;
use App\Services\Crawler\PembacaRss;
use PHPUnit\Framework\TestCase;

class PembacaRssTest extends TestCase
{
    private PembacaRss $pembaca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pembaca = new PembacaRss(new NormalisasiUrl);
    }

    private function rss(string $awalan = ''): string
    {
        return $awalan.<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
          <item>
            <title>Pemkot Kendari Perbaiki Drainase</title>
            <link>https://contoh.id/drainase</link>
            <pubDate>Sat, 02 Aug 2026 11:44:31 +0000</pubDate>
            <description><![CDATA[<p>Ringkasan berita.</p>]]></description>
          </item>
        </channel></rss>
        XML;
    }

    public function test_membaca_rss_biasa(): void
    {
        $item = $this->pembaca->baca($this->rss(), 'https://contoh.id/feed');

        $this->assertCount(1, $item);
        $this->assertSame('Pemkot Kendari Perbaiki Drainase', $item[0]->judul);
        $this->assertSame('https://contoh.id/drainase', $item[0]->url);
        $this->assertSame('Ringkasan berita.', $item[0]->ringkasan);
    }

    /**
     * Sejumlah situs WordPress mencetak baris kosong sebelum deklarasi XML
     * karena plugin yang menyisipkan sesuatu. Feed-nya sah, hanya kotor di
     * depan — menolaknya berarti kehilangan seluruh media itu.
     */
    public function test_feed_dengan_baris_kosong_sebelum_deklarasi_tetap_terbaca(): void
    {
        $item = $this->pembaca->baca($this->rss("\r\n\r\n\r\n"), 'https://contoh.id/feed');

        $this->assertCount(1, $item, 'Feed dengan spasi di depan seharusnya tetap terbaca.');
    }

    public function test_feed_dengan_bom_tetap_terbaca(): void
    {
        $item = $this->pembaca->baca($this->rss("\u{FEFF}"), 'https://contoh.id/feed');

        $this->assertCount(1, $item);
    }

    public function test_feed_atom_terbaca(): void
    {
        $atom = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
          <entry>
            <title>Judul Atom</title>
            <link rel="alternate" href="https://contoh.id/atom-1"/>
            <published>2026-08-02T11:44:31Z</published>
          </entry>
        </feed>
        XML;

        $item = $this->pembaca->baca($atom, 'https://contoh.id/feeds/posts/default');

        $this->assertCount(1, $item);
        $this->assertSame('https://contoh.id/atom-1', $item[0]->url);
    }

    public function test_xml_rusak_ditolak_dengan_pesan_yang_menyebut_feednya(): void
    {
        $this->expectExceptionMessageMatches('/contoh\.id/');

        // Tag tidak tertutup — benar-benar tidak bisa diurai.
        $this->pembaca->baca('<rss><channel><item></channel>', 'https://contoh.id/feed');
    }

    /**
     * Halaman yang kebetulan XML sah tapi bukan feed bukan kegagalan: crawler
     * mencatatnya sebagai "sebagian" dengan keterangan, bukan menaikkan
     * hitungan gagal yang bisa menonaktifkan sumbernya.
     */
    public function test_xml_sah_tanpa_item_menghasilkan_daftar_kosong(): void
    {
        $this->assertSame([], $this->pembaca->baca('<html>bukan feed</html>', 'https://contoh.id/feed'));
    }
}
