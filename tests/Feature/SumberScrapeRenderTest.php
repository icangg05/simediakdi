<?php

namespace Tests\Feature;

use App\Enums\TipeSumber;
use App\Models\Artikel;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Services\Crawler\PengunduhHalaman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * Tipe `scrape_render` untuk halaman indeks yang dirakit JavaScript.
 *
 * tempo.co mengirim kerangka kosong lalu mengambil daftar beritanya sendiri
 * sesudah hidrasi, jadi pengunduh biasa tidak menemukan satu tautan pun. Yang
 * diuji di sini adalah percabangannya, bukan Chromium-nya: `scrape_render`
 * wajib lewat jalur render, dan tipe lain wajib tidak, karena satu render
 * memakan belasan detik CPU untuk hasil yang bagi RSS sama saja.
 */
class SumberScrapeRenderTest extends TestCase
{
    use RefreshDatabase;

    private const HALAMAN = <<<'HTML'
    <html><body>
      <figure class="contents">
        <figcaption><p><a href="/berita/pemkot-kendari-benahi-bundaran-1">Pemkot Kendari Benahi Bundaran</a></p></figcaption>
      </figure>
      <figure class="contents">
        <figcaption><p><a href="/berita/pemkot-yogya-lelang-kendaraan-2">Pemkot Yogya Lelang Kendaraan</a></p></figcaption>
      </figure>
    </body></html>
    HTML;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    private function sumber(TipeSumber $tipe): SumberFeed
    {
        $media = Media::withoutGlobalScopes()->create([
            'nama' => 'Tempo',
            'slug' => 'tempo',
            'domain' => 'tempo.co',
            'aktif' => true,
        ]);

        return SumberFeed::withoutGlobalScopes()->create([
            'media_id' => $media->id,
            'nama' => 'Pencarian',
            'tipe' => $tipe,
            'url' => 'https://www.tempo.co/search?q=pemkot+kendari',
            'selector' => ['item' => 'figure.contents', 'judul' => 'figcaption p', 'tautan' => 'figcaption a'],
            'kata_kunci' => 'Kendari',
            'interval_menit' => 180,
            'aktif' => true,
        ]);
    }

    public function test_scrape_render_lewat_jalur_render_dan_menunggu_selector_item(): void
    {
        $this->sumber(TipeSumber::ScrapeRender);

        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        // Selector item ikut dikirim sebagai syarat tunggu. Tanpa itu peramban
        // hanya menunggu jaringan sepi, dan jaringan bisa sepi justru sebelum
        // permintaan daftar beritanya berangkat.
        $pengunduh->shouldReceive('unduhTerender')
            ->once()
            ->with('https://www.tempo.co/search?q=pemkot+kendari', 'figure.contents')
            ->andReturn(self::HALAMAN);
        $pengunduh->shouldReceive('unduh')->never();
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $this->artisan('crawl:feeds --paksa')->assertSuccessful();

        // Dua item terbaca, satu tersaring karena "Pemkot Yogya Lelang
        // Kendaraan" tidak memuat kata Kendari. Pencarian Tempo mencocokkan
        // kata secara longgar, jadi saringan itu memang dibutuhkan.
        $this->assertSame(1, Artikel::withoutGlobalScopes()->count());
        $this->assertSame(
            'https://www.tempo.co/berita/pemkot-kendari-benahi-bundaran-1',
            Artikel::withoutGlobalScopes()->value('url'),
        );
    }

    public function test_scrape_biasa_tidak_menyalakan_peramban(): void
    {
        $this->sumber(TipeSumber::Scrape);

        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->once()->andReturn(self::HALAMAN);
        $pengunduh->shouldReceive('unduhTerender')->never();
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $this->artisan('crawl:feeds --paksa')->assertSuccessful();

        $this->assertSame(1, Artikel::withoutGlobalScopes()->count());
    }
}
