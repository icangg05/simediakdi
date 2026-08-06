<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Models\SumberFeed;
use App\Services\Crawler\PengunduhHalaman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * Tombol Crawl sekarang di halaman Log crawl, beserta penjaga sumber mati.
 */
class CrawlManualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    private function sumber(bool $aktif): SumberFeed
    {
        $media = Media::create(['nama' => 'Contoh', 'slug' => 'contoh', 'domain' => 'contoh.test']);

        return SumberFeed::create([
            'media_id' => $media->id,
            'nama' => 'Contoh RSS',
            'tipe' => 'rss',
            'url' => 'https://contoh.test/rss',
            'aktif' => $aktif,
        ]);
    }

    public function test_paksa_tidak_membangunkan_sumber_yang_sudah_dimatikan(): void
    {
        $this->sumber(aktif: false);

        // Kalau penjaganya lolos, pengunduh ini akan dipanggil. Mockery yang
        // tidak menerima panggilan apa pun justru yang diharapkan di sini.
        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldNotReceive('unduh');
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $this->artisan('crawl:feeds --paksa')->assertSuccessful();

        $this->assertDatabaseCount('log_crawl', 0);
    }

    public function test_sumber_disebut_langsung_tetap_bisa_dijalankan_meski_mati(): void
    {
        $sumber = $this->sumber(aktif: false);

        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->once()->andReturn(
            '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel></channel></rss>',
        );
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        // Admin yang menyebut satu id memang sedang menguji sumber yang baru
        // diperbaiki, jadi jalur ini sengaja dibiarkan terbuka.
        $this->artisan("crawl:feeds --sumber={$sumber->id}")->assertSuccessful();

        $this->assertDatabaseCount('log_crawl', 1);
    }

    public function test_tombol_crawl_melempar_pekerjaan_ke_antrean(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/admin/log-crawl/jalankan')
            ->assertRedirect()
            ->assertSessionHas('sukses');

        // Bukan dijalankan langsung di dalam permintaan: satu crawl penuh
        // menarik puluhan feed dan akan mati oleh batas waktu PHP-FPM.
        Bus::assertDispatched(QueuedCommand::class);
    }

    public function test_tombol_crawl_menolak_ketukan_ketiga_dalam_semenit(): void
    {
        $pengguna = User::factory()->create();

        $this->actingAs($pengguna)->post('/admin/log-crawl/jalankan')->assertRedirect();
        $this->actingAs($pengguna)->post('/admin/log-crawl/jalankan')->assertRedirect();
        $this->actingAs($pengguna)->post('/admin/log-crawl/jalankan')->assertStatus(429);
    }
}
