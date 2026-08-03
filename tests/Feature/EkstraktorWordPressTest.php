<?php

namespace Tests\Feature;

use App\Services\Crawler\EkstraktorWordPress;
use App\Services\Crawler\GagalMengunduh;
use App\Services\Crawler\PembersihHtml;
use App\Services\Crawler\PengunduhHalaman;
use App\Services\Crawler\UrlDitolak;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EkstraktorWordPressTest extends TestCase
{
    private const TANGGAPAN = <<<'JSON'
    [{
      "date_gmt": "2026-08-02T11:44:31",
      "title": { "rendered": "Bupati Ikbar Gratiskan Biaya Pendidikan" },
      "content": { "rendered": "<p>Paragraf pertama berita.</p><p>Paragraf kedua &amp; penutup.</p>" },
      "excerpt": { "rendered": "<p>Ringkasan singkat berita.</p>" },
      "_embedded": {
        "author": [{ "name": "Admin-HL" }],
        "wp:featuredmedia": [{ "source_url": "https://contoh.id/gambar.jpg" }]
      }
    }]
    JSON;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function ekstraktor(PengunduhHalaman $pengunduh): EkstraktorWordPress
    {
        return new EkstraktorWordPress($pengunduh, new PembersihHtml);
    }

    private function pengunduh(): Mockery\MockInterface
    {
        return Mockery::mock(PengunduhHalaman::class);
    }

    public function test_mengambil_isi_lengkap_dari_api(): void
    {
        $pengunduh = $this->pengunduh();
        $pengunduh->shouldReceive('unduh')
            ->once()
            ->with('https://contoh.id/wp-json/wp/v2/posts?slug=judul-berita&_embed=1')
            ->andReturn(self::TANGGAPAN);

        $hasil = $this->ekstraktor($pengunduh)->ekstrak('https://contoh.id/2026/08/02/judul-berita/');

        $this->assertNotNull($hasil);
        $this->assertSame('Bupati Ikbar Gratiskan Biaya Pendidikan', $hasil->judul);
        $this->assertStringContainsString('Paragraf pertama berita.', $hasil->isi);
        $this->assertStringContainsString('Paragraf kedua & penutup.', $hasil->isi);
        $this->assertSame('Admin-HL', $hasil->penulis);
        $this->assertSame('https://contoh.id/gambar.jpg', $hasil->gambarUrl);
        $this->assertSame('Ringkasan singkat berita.', $hasil->ringkasan);
    }

    /**
     * date_gmt dikirim tanpa penanda zona waktu. Kalau diurai memakai zona
     * aplikasi (WITA), waktunya meleset delapan jam ke belakang.
     */
    public function test_date_gmt_diurai_sebagai_utc_bukan_zona_aplikasi(): void
    {
        $pengunduh = $this->pengunduh();
        $pengunduh->shouldReceive('unduh')->once()->andReturn(self::TANGGAPAN);

        // Aplikasi berjalan di UTC, jadi kesalahan ini tidak akan muncul dengan
        // sendirinya di tes. Zona sengaja digeser ke WITA supaya kalau suatu
        // saat parsing kembali polos, tes ini yang gagal, bukan penggunanya
        // yang menemukan tanggal berita meleset delapan jam.
        $asal = date_default_timezone_get();
        date_default_timezone_set('Asia/Makassar');

        try {
            $hasil = $this->ekstraktor($pengunduh)->ekstrak('https://contoh.id/judul-berita/');
        } finally {
            date_default_timezone_set($asal);
        }

        $this->assertSame('2026-08-02T11:44:31+00:00', $hasil->dipublikasikanAt->toIso8601String());
    }

    public function test_situs_bukan_wordpress_diingat_dan_tidak_dicoba_lagi(): void
    {
        $pengunduh = $this->pengunduh();
        // Justru ini intinya: percobaan kedua tidak boleh menembak jaringan lagi.
        $pengunduh->shouldReceive('unduh')
            ->once()
            ->andThrow(new GagalMengunduh('menjawab HTTP 404.', 404));

        $ekstraktor = $this->ekstraktor($pengunduh);

        $this->assertNull($ekstraktor->ekstrak('https://detik.com/berita/judul'));
        $this->assertNull($ekstraktor->ekstrak('https://detik.com/berita/judul-lain'));
    }

    /**
     * Timeout atau 502 hanya berarti sedang apes. Jangan mematikan jalur API
     * sehari penuh karena satu permintaan yang gagal.
     */
    public function test_gangguan_sesaat_tidak_mematikan_jalur_api(): void
    {
        $pengunduh = $this->pengunduh();
        $pengunduh->shouldReceive('unduh')
            ->twice()
            ->andThrow(new GagalMengunduh('Tidak dapat menghubungi: timeout', null));

        $ekstraktor = $this->ekstraktor($pengunduh);

        $this->assertNull($ekstraktor->ekstrak('https://britakita.net/judul'));
        $this->assertNull($ekstraktor->ekstrak('https://britakita.net/judul-lain'));
    }

    /**
     * API hidup tapi artikelnya tidak lewat endpoint `posts`, custom post type
     * atau permalink `?p=123`. Jalur API tetap dipakai untuk artikel lain.
     */
    public function test_slug_tidak_ketemu_hanya_membatalkan_artikel_itu(): void
    {
        $pengunduh = $this->pengunduh();
        $pengunduh->shouldReceive('unduh')->twice()->andReturn('[]', self::TANGGAPAN);

        $ekstraktor = $this->ekstraktor($pengunduh);

        $this->assertNull($ekstraktor->ekstrak('https://contoh.id/tidak-ada/'));
        $this->assertNotNull($ekstraktor->ekstrak('https://contoh.id/judul-berita/'));
    }

    public function test_robots_yang_melarang_wp_json_membuat_jalur_api_dilewati(): void
    {
        $pengunduh = $this->pengunduh();
        $pengunduh->shouldReceive('unduh')
            ->once()
            ->andThrow(new UrlDitolak('robots.txt situs melarang pengambilan.'));

        $ekstraktor = $this->ekstraktor($pengunduh);

        $this->assertNull($ekstraktor->ekstrak('https://sultratv.id/judul/'));
        $this->assertNull($ekstraktor->ekstrak('https://sultratv.id/judul-lain/'));
    }

    public function test_url_tanpa_slug_dilewati_tanpa_menembak_jaringan(): void
    {
        $pengunduh = $this->pengunduh();
        $pengunduh->shouldNotReceive('unduh');

        $this->assertNull($this->ekstraktor($pengunduh)->ekstrak('https://contoh.id/'));
    }
}
