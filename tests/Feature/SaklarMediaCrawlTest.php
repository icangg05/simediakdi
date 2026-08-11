<?php

namespace Tests\Feature;

use App\Enums\TipeSumber;
use App\Models\Media;
use App\Models\SumberFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `media.aktif` adalah saklar induk pengambilan berita.
 *
 * Sebelum ini `crawl:feeds` tidak pernah melihat kolom itu sama sekali, jadi
 * media yang sudah dinonaktifkan admin tetap ditarik selama sumber feed-nya
 * masih hidup.
 */
class SaklarMediaCrawlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tidak ada satu pun permintaan keluar yang boleh benar-benar terjadi.
        // Kalau tes ini sampai memanggil jaringan, artinya penjaganya bocor.
        Http::preventStrayRequests();
    }

    private function sumberUntuk(bool $mediaAktif): SumberFeed
    {
        $media = Media::withoutGlobalScopes()->create([
            'nama' => 'Kendari Pos',
            'slug' => 'kendari-pos-'.fake()->unique()->numerify('####'),
            'domain' => fake()->unique()->domainName(),
            'aktif' => $mediaAktif,
        ]);

        return SumberFeed::withoutGlobalScopes()->create([
            'media_id' => $media->id,
            'nama' => 'RSS utama',
            'tipe' => TipeSumber::Rss,
            'url' => 'https://contoh.id/feed',
            'interval_menit' => 30,
            'aktif' => true,
            'dijalankan_terakhir_at' => null,
        ]);
    }

    public function test_sumber_milik_media_nonaktif_tidak_dijalankan(): void
    {
        $sumber = $this->sumberUntuk(mediaAktif: false);

        $this->artisan('crawl:feeds')
            ->expectsOutputToContain('Tidak ada sumber yang jatuh tempo')
            ->assertSuccessful();

        $this->assertNull(
            $sumber->fresh()->dijalankan_terakhir_at,
            'Sumber milik media nonaktif tidak boleh pernah disentuh.',
        );
    }

    /** Saklar yang bisa ditembus jalur manual bukan saklar. */
    public function test_paksa_dan_media_pun_tidak_menembus_saklar(): void
    {
        $sumber = $this->sumberUntuk(mediaAktif: false);

        $this->artisan('crawl:feeds', ['--paksa' => true, '--media' => $sumber->media_id])
            ->assertSuccessful();

        $this->assertNull($sumber->fresh()->dijalankan_terakhir_at);
    }

    public function test_sumber_milik_media_terhapus_tidak_dijalankan(): void
    {
        $sumber = $this->sumberUntuk(mediaAktif: true);
        Media::withoutGlobalScopes()->find($sumber->media_id)->delete();

        $this->artisan('crawl:feeds')->assertSuccessful();

        $this->assertNull($sumber->fresh()->dijalankan_terakhir_at);
    }

    /** Sisa Google News yang sudah dicabut: tidak punya media, tidak punya saklar. */
    public function test_sumber_tanpa_media_tidak_dijalankan(): void
    {
        $sumber = SumberFeed::withoutGlobalScopes()->create([
            'media_id' => null,
            'nama' => 'Sisa lintas media',
            'tipe' => TipeSumber::Rss,
            'url' => 'https://contoh.id/feed',
            'interval_menit' => 30,
            'aktif' => true,
        ]);

        $this->artisan('crawl:feeds')->assertSuccessful();

        $this->assertNull($sumber->fresh()->dijalankan_terakhir_at);
    }
}
