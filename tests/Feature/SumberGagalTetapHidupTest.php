<?php

namespace Tests\Feature;

use App\Enums\TipeSumber;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Services\Crawler\GagalMengunduh;
use App\Services\Crawler\PengunduhHalaman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Penonaktifan otomatis F-07 sudah dicabut.
 *
 * Penyebab kegagalan hampir selalu di luar kendali kita dan sembuh sendiri:
 * origin di balik Cloudflare menjawab 525 beberapa jam, CDN media nasional
 * menolak koneksi karena pembatasan sesaat, atau satu judul memuat `&`
 * telanjang yang merobohkan seluruh XML. Sumber yang mati diam-diam berarti
 * berita satu media berhenti masuk tanpa ada yang menyadarinya.
 *
 * Tesnya ditulis karena aturan yang dicabut adalah aturan yang paling mudah
 * kembali sendiri lewat satu baris di kemudian hari.
 */
class SumberGagalTetapHidupTest extends TestCase
{
    use RefreshDatabase;

    private function sumber(): SumberFeed
    {
        $media = Media::withoutGlobalScopes()->create([
            'nama' => 'Media Uji',
            'slug' => 'media-uji',
            'domain' => 'uji.test',
            'aktif' => true,
        ]);

        return SumberFeed::withoutGlobalScopes()->create([
            'media_id' => $media->id,
            'nama' => 'RSS utama',
            'tipe' => TipeSumber::Rss,
            'url' => 'https://uji.test/feed',
            'interval_menit' => 30,
            'aktif' => true,
        ]);
    }

    private function selaluGagal(): void
    {
        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->andThrow(new GagalMengunduh('Situsnya sedang tumbang.'));
        $this->app->instance(PengunduhHalaman::class, $pengunduh);
    }

    public function test_gagal_berkali_kali_tidak_mematikan_sumber(): void
    {
        $sumber = $this->sumber();
        $this->selaluGagal();

        // Dua kali lipat ambang lama, supaya tesnya tidak lolos hanya karena
        // batasnya kebetulan dinaikkan.
        for ($i = 0; $i < 10; $i++) {
            $this->artisan('crawl:feeds --paksa')->assertSuccessful();
        }

        $sumber->refresh();

        $this->assertTrue($sumber->aktif, 'Sumber yang gagal berulang harus tetap aktif dan terus mencoba.');
        $this->assertSame(10, $sumber->gagal_berturut, 'Hitungan gagal tetap naik supaya masalahnya terlihat.');
        $this->assertStringContainsString('tumbang', (string) $sumber->pesan_error_terakhir);
    }

    /** Satu keberhasilan mengembalikan hitungannya ke nol. */
    public function test_berhasil_sekali_mereset_hitungan(): void
    {
        $sumber = $this->sumber();
        $this->selaluGagal();

        $this->artisan('crawl:feeds --paksa')->assertSuccessful();
        $this->assertSame(1, $sumber->fresh()->gagal_berturut);

        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->andReturn(
            '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel></channel></rss>',
        );
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $this->artisan('crawl:feeds --paksa')->assertSuccessful();

        $sumber->refresh();

        $this->assertSame(0, $sumber->gagal_berturut);
        $this->assertNull($sumber->pesan_error_terakhir);
        $this->assertTrue($sumber->aktif);
    }

    /** Sumber yang dimatikan admin tetap mati. Yang dicabut hanya saklar sistem. */
    public function test_sumber_yang_dimatikan_admin_tetap_tidak_dijalankan(): void
    {
        $sumber = $this->sumber();
        $sumber->update(['aktif' => false]);

        $this->artisan('crawl:feeds --paksa')->assertSuccessful();

        $this->assertNull($sumber->fresh()->dijalankan_terakhir_at);
    }
}
