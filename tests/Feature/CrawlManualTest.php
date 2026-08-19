<?php

namespace Tests\Feature;

use App\Models\LogCrawl;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Models\User;
use App\Services\Crawler\GagalMengunduh;
use App\Services\Crawler\PengunduhHalaman;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * Tombol Crawl sekarang, baik yang menarik semuanya dari halaman Log crawl
 * maupun yang menarik satu media dari halaman Media, beserta penjaga sumber
 * mati dan tampilan jadwal berikutnya.
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

    /**
     * Baris log berstatus `berjalan` selama pengambilan, bukan `gagal`.
     *
     * Dulu baris dibuat langsung berstatus gagal sebagai keadaan aman, dan
     * akibatnya halaman Log crawl menampilkan baris merah selama 7 sampai 16
     * detik untuk pekerjaan yang berjalan normal. Admin membaca itu sebagai
     * sistem yang rusak setiap kali menekan tombol Crawl sekarang.
     *
     * Statusnya diperiksa dari dalam pengunduh palsu, yaitu satu-satunya titik
     * ketika pekerjaannya benar-benar sedang berlangsung.
     */
    public function test_status_log_berjalan_selama_pengambilan_lalu_sukses(): void
    {
        $sumber = $this->sumber(aktif: true);

        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->once()->andReturnUsing(function () {
            $this->assertSame('berjalan', LogCrawl::query()->value('status'));

            return '<?xml version="1.0"?><rss><channel><item><title>Judul</title>'
                .'<link>https://contoh.test/berita-1</link></item></channel></rss>';
        });
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $this->artisan('crawl:feeds --paksa')->assertSuccessful();

        $log = LogCrawl::query()->firstOrFail();

        $this->assertSame('sukses', $log->status);
        $this->assertNotNull($log->selesai_at);
        $this->assertSame($sumber->id, $log->sumber_feed_id);
    }

    /** Kegagalan sungguhan tetap tercatat gagal, bukan tertinggal berjalan. */
    public function test_kegagalan_tetap_tercatat_gagal(): void
    {
        $this->sumber(aktif: true);

        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->andThrow(new GagalMengunduh('Situsnya sedang tumbang.'));
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $this->artisan('crawl:feeds --paksa')->assertSuccessful();

        $this->assertSame('gagal', LogCrawl::query()->value('status'));
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

    public function test_tombol_crawl_per_media_melempar_pekerjaan_ke_antrean(): void
    {
        $sumber = $this->sumber(aktif: true);

        $this->actingAs(User::factory()->create())
            ->post("/admin/media/{$sumber->media_id}/crawl")
            ->assertRedirect()
            ->assertSessionHas('sukses');

        Bus::assertDispatched(QueuedCommand::class);
    }

    public function test_media_tanpa_sumber_aktif_tidak_menjalankan_apa_pun(): void
    {
        $sumber = $this->sumber(aktif: false);

        // Tanpa penjaga ini tombolnya menjawab "dijalankan" untuk pekerjaan
        // yang tidak pernah punya satu pun sumber untuk ditarik.
        $this->actingAs(User::factory()->create())
            ->post("/admin/media/{$sumber->media_id}/crawl")
            ->assertRedirect()
            ->assertSessionHas('galat');

        Bus::assertNothingDispatched();
    }

    /** Hanya menarik sumber milik media yang diminta. */
    public function test_opsi_media_menyaring_sumber(): void
    {
        $ikut = $this->sumber(aktif: true);

        $lain = Media::create(['nama' => 'Lain', 'slug' => 'lain', 'domain' => 'lain.test']);
        SumberFeed::create([
            'media_id' => $lain->id,
            'nama' => 'Lain RSS',
            'tipe' => 'rss',
            'url' => 'https://lain.test/rss',
        ]);

        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->once()->andReturn(
            '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel></channel></rss>',
        );
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $this->artisan("crawl:feeds --media={$ikut->media_id} --paksa")->assertSuccessful();

        $this->assertDatabaseCount('log_crawl', 1);
        $this->assertDatabaseHas('log_crawl', ['sumber_feed_id' => $ikut->id]);
    }

    /**
     * Jadwal berikutnya dibaca dari penjadwal, dan penjadwalnya harus sudah
     * di-bootstrap. Tanpa itu daftar event-nya kosong dan layar selalu
     * menulis "tidak terjadwal" meski jadwalnya ada di routes/console.php.
     */
    public function test_halaman_log_crawl_tahu_jadwal_berikutnya(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/log-crawl')
            ->assertInertia(fn ($p) => $p->whereNot('crawlBerikutnya', null));
    }
}
