<?php

namespace Tests\Feature;

use App\Models\Artikel;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Services\Crawler\PengunduhHalaman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * F-05 dipenuhi lewat feed milik media nasional sendiri, bukan Google News yang
 * robots.txt-nya melarang seluruh path. Feed utuhnya didominasi berita di luar
 * Kendari, jadi disaring kata kunci sebelum artikel disimpan.
 *
 * Saringan yang membuang data diam-diam perlu tesnya sendiri: kalau terlalu
 * ketat, liputan Kendari di media nasional hilang tanpa jejak apa pun.
 */
class SaringanKataKunciFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    private function feed(string ...$judul): string
    {
        $item = '';

        foreach ($judul as $i => $satu) {
            $item .= "<item><title>{$satu}</title><link>https://nasional.test/{$i}</link>"
                .'<description>Ringkasan berita.</description></item>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'.$item.'</channel></rss>';
    }

    private function sumber(?string $kataKunci): SumberFeed
    {
        $media = Media::create(['nama' => 'Nasional', 'slug' => 'nasional', 'domain' => 'nasional.test']);

        return SumberFeed::create([
            'media_id' => $media->id,
            'nama' => 'Nasional RSS',
            'tipe' => 'rss',
            'url' => 'https://nasional.test/rss',
            'kata_kunci' => $kataKunci,
        ]);
    }

    private function jalankan(string $xml): void
    {
        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->andReturn($xml);
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $this->artisan('crawl:feeds --paksa');
    }

    public function test_hanya_menyimpan_item_yang_menyebut_kata_kunci(): void
    {
        $this->sumber('Kendari');

        $this->jalankan($this->feed(
            'Wali Kota Kendari Resmikan Pasar',
            'Banjir Melanda Jakarta Selatan',
            'Harga Emas Naik di Awal Pekan',
        ));

        $this->assertSame(1, Artikel::withoutGlobalScopes()->count());
        $this->assertStringContainsString('Kendari', Artikel::withoutGlobalScopes()->value('judul'));
    }

    public function test_pencocokan_mengabaikan_besar_kecil_huruf(): void
    {
        $this->sumber('kendari');

        $this->jalankan($this->feed('KENDARI Tuan Rumah Rapat Nasional'));

        $this->assertSame(1, Artikel::withoutGlobalScopes()->count());
    }

    public function test_kata_kunci_di_ringkasan_ikut_dihitung(): void
    {
        $this->sumber('Ringkasan');

        // Judul tidak menyebut, ringkasannya menyebut — tetap masuk.
        $this->jalankan($this->feed('Judul Tanpa Kata Itu'));

        $this->assertSame(1, Artikel::withoutGlobalScopes()->count());
    }

    /** Sumber tanpa kata kunci tidak boleh ikut tersaring. */
    public function test_sumber_tanpa_kata_kunci_menyimpan_semuanya(): void
    {
        $this->sumber(null);

        $this->jalankan($this->feed('Berita Satu', 'Berita Dua', 'Berita Tiga'));

        $this->assertSame(3, Artikel::withoutGlobalScopes()->count());
    }

    /**
     * Feed yang seluruh isinya tersaring bukan kegagalan — hari itu memang
     * tidak ada liputan Kendari. Menaikkan hitungan gagal akan menonaktifkan
     * sumbernya setelah lima hari sepi.
     */
    public function test_semua_tersaring_tidak_dianggap_kegagalan(): void
    {
        $sumber = $this->sumber('Kendari');

        $this->jalankan($this->feed('Berita Jakarta', 'Berita Surabaya'));

        $sumber->refresh();

        $this->assertSame(0, $sumber->gagal_berturut);
        $this->assertTrue($sumber->aktif);
        $this->assertNotNull($sumber->berhasil_terakhir_at);
    }

    public function test_log_crawl_mencatat_berapa_yang_tersaring(): void
    {
        $sumber = $this->sumber('Kendari');

        $this->jalankan($this->feed('Berita Kendari', 'Berita Jakarta', 'Berita Medan'));

        $log = $sumber->logCrawl()->first();

        $this->assertSame(3, $log->jumlah_ditemukan);
        $this->assertSame(1, $log->jumlah_baru);
        $this->assertStringContainsString('2 item dibuang saringan kata kunci', $log->pesan);
    }
}
