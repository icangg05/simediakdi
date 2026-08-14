<?php

namespace Tests\Feature;

use App\Enums\TipeSumber;
use App\Jobs\TemukanFeedMedia;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Models\User;
use App\Services\Crawler\PenemuFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sumber feed dikelola dari halaman medianya, dan media adalah saklar induknya.
 */
class PengelolaanMediaTest extends TestCase
{
    use RefreshDatabase;

    private function media(array $atribut = []): Media
    {
        return Media::withoutGlobalScopes()->create([
            'nama' => 'Kendari Pos',
            'slug' => 'kendari-pos-'.fake()->unique()->numerify('####'),
            'domain' => fake()->unique()->domainName(),
            'aktif' => true,
            ...$atribut,
        ]);
    }

    public function test_halaman_detail_menampilkan_sumber_feed_medianya(): void
    {
        $this->actingAs(User::factory()->create());

        $media = $this->media();
        SumberFeed::withoutGlobalScopes()->create([
            'media_id' => $media->id, 'nama' => 'RSS utama',
            'tipe' => TipeSumber::Rss, 'url' => 'https://contoh.id/feed',
            'interval_menit' => 30, 'aktif' => true,
        ]);

        // Milik media lain, tidak boleh ikut muncul.
        $lain = $this->media(['nama' => 'Media Lain']);
        SumberFeed::withoutGlobalScopes()->create([
            'media_id' => $lain->id, 'nama' => 'RSS tetangga',
            'tipe' => TipeSumber::Rss, 'url' => 'https://lain.id/feed',
            'interval_menit' => 30, 'aktif' => true,
        ]);

        $this->get("/admin/media/{$media->id}")
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->component('admin/media/Detail')
                ->has('sumberFeed', 1)
                ->where('sumberFeed.0.nama', 'RSS utama'));
    }

    public function test_sumber_feed_dibuat_lewat_rute_medianya(): void
    {
        $this->actingAs(User::factory()->create());

        $media = $this->media();

        $this->post("/admin/media/{$media->id}/sumber-feed", [
            'nama' => 'RSS daerah',
            'tipe' => 'rss',
            'url' => 'https://contoh.id/daerah/feed',
            'interval_menit' => 30,
            'aktif' => true,
        ])->assertRedirect();

        $this->assertSame($media->id, SumberFeed::withoutGlobalScopes()->first()->media_id);
    }

    /** Pemilik ditentukan rute, bukan badan permintaan. */
    public function test_media_id_di_badan_permintaan_diabaikan(): void
    {
        $this->actingAs(User::factory()->create());

        $media = $this->media();
        $korban = $this->media(['nama' => 'Media Korban']);

        $this->post("/admin/media/{$media->id}/sumber-feed", [
            'media_id' => $korban->id,
            'nama' => 'RSS selundupan',
            'tipe' => 'rss',
            'url' => 'https://contoh.id/feed',
            'interval_menit' => 30,
        ])->assertRedirect();

        $this->assertSame($media->id, SumberFeed::withoutGlobalScopes()->first()->media_id);
    }

    public function test_sumber_feed_milik_media_lain_tidak_bisa_diubah(): void
    {
        $this->actingAs(User::factory()->create());

        $media = $this->media();
        $lain = $this->media(['nama' => 'Media Lain']);

        $sumber = SumberFeed::withoutGlobalScopes()->create([
            'media_id' => $lain->id, 'nama' => 'RSS tetangga',
            'tipe' => TipeSumber::Rss, 'url' => 'https://lain.id/feed',
            'interval_menit' => 30, 'aktif' => true,
        ]);

        $this->put("/admin/media/{$media->id}/sumber-feed/{$sumber->id}", [
            'nama' => 'Dibajak',
            'tipe' => 'rss',
            'url' => 'https://contoh.id/feed',
            'interval_menit' => 30,
        ])->assertNotFound();

        $this->assertSame('RSS tetangga', $sumber->fresh()->nama);
    }

    public function test_saklar_media_membalik_aktif_tanpa_menghapus(): void
    {
        $this->actingAs(User::factory()->create());

        $media = $this->media();

        $this->post("/admin/media/{$media->id}/aktif")->assertRedirect();
        $this->assertFalse($media->fresh()->aktif);
        $this->assertNotNull(Media::withoutGlobalScopes()->find($media->id), 'Media tidak boleh ikut terhapus.');

        $this->post("/admin/media/{$media->id}/aktif")->assertRedirect();
        $this->assertTrue($media->fresh()->aktif);
    }

    public function test_media_nonaktif_tidak_bisa_ditarik_manual(): void
    {
        $this->actingAs(User::factory()->create());

        $media = $this->media(['aktif' => false]);
        SumberFeed::withoutGlobalScopes()->create([
            'media_id' => $media->id, 'nama' => 'RSS utama',
            'tipe' => TipeSumber::Rss, 'url' => 'https://contoh.id/feed',
            'interval_menit' => 30, 'aktif' => true,
        ]);

        $this->post("/admin/media/{$media->id}/crawl")
            ->assertSessionHas('galat');
    }

    public function test_media_baru_mengantrekan_pencarian_feed(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $this->post('/admin/media', [
            'nama' => 'Media Baru',
            'slug' => 'media-baru',
            'jenis' => 'online',
            'tier' => 'lokal',
            'domain' => 'medianbaru.id',
            'aktif' => true,
        ])->assertRedirect();

        Queue::assertPushed(TemukanFeedMedia::class);
    }

    public function test_media_baru_selalu_online_dan_slug_duplikat_diberi_nomor(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        // Baris soft-delete tetap menahan slug karena indeks unik database
        // tidak menghapus nilainya.
        $this->media([
            'nama' => 'Media Baru',
            'slug' => 'media-baru',
            'domain' => 'media-lama.id',
        ])->delete();

        $this->get('/admin/media/create')
            ->assertInertia(fn ($halaman) => $halaman
                ->component('admin/media/Form')
                ->where('slugTerpakai.0', 'media-baru'));

        $this->post('/admin/media', [
            'nama' => 'Media Baru',
            // Keduanya sengaja dimanipulasi. Server harus memakai nilai hasil
            // perhitungannya sendiri karena field slug terkunci di antarmuka
            // dan field jenis tidak lagi ditampilkan.
            'slug' => 'slug-palsu',
            'jenis' => 'radio',
            'tier' => 'lokal',
            'domain' => 'media-baru.id',
            'aktif' => true,
        ])->assertRedirect();

        $media = Media::withoutGlobalScopes()->where('domain', 'media-baru.id')->firstOrFail();

        $this->assertSame('online', $media->jenis);
        $this->assertSame('media-baru-2', $media->slug);
        $this->assertSame(1, Media::withoutGlobalScopes()->where('slug', 'media-baru-2')->count());
    }

    public function test_pesan_validasi_media_menggunakan_bahasa_indonesia(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from('/admin/media/create')
            ->post('/admin/media', [
                'nama' => '',
                'tier' => 'bukan-tier',
                'url_website' => 'alamat situs tidak valid',
            ])
            ->assertRedirect('/admin/media/create')
            ->assertSessionHasErrors([
                'nama' => 'Kolom nama wajib diisi.',
                'tier' => 'Pilihan tier media tidak valid.',
                'url_website' => 'Kolom URL situs web harus berupa URL yang valid.',
            ]);
    }

    /** Alamat hasil isian tangan tidak boleh ditimpa hasil tebakan. */
    public function test_pencarian_feed_tidak_menimpa_sumber_yang_sudah_ada(): void
    {
        $media = $this->media();
        SumberFeed::withoutGlobalScopes()->create([
            'media_id' => $media->id, 'nama' => 'RSS isian tangan',
            'tipe' => TipeSumber::Rss, 'url' => 'https://contoh.id/manual',
            'interval_menit' => 30, 'aktif' => true,
        ]);

        // Penemu tidak boleh dipanggil sama sekali dalam keadaan ini.
        $penemu = $this->createMock(PenemuFeed::class);
        $penemu->expects($this->never())->method('cari');

        (new TemukanFeedMedia($media->id))->handle($penemu);

        $this->assertSame(1, SumberFeed::withoutGlobalScopes()->count());
        $this->assertNotNull($media->fresh()->feed_dicari_at);
    }

    public function test_pencarian_feed_mendaftarkan_alamat_yang_ditemukan(): void
    {
        $media = $this->media();

        $penemu = $this->createMock(PenemuFeed::class);
        $penemu->method('cari')->willReturn('https://contoh.id/feed');

        (new TemukanFeedMedia($media->id))->handle($penemu);

        $sumber = SumberFeed::withoutGlobalScopes()->first();
        $this->assertSame('https://contoh.id/feed', $sumber->url);
        $this->assertSame($media->id, $sumber->media_id);
        $this->assertTrue($sumber->aktif);
        $this->assertNotNull($media->fresh()->feed_dicari_at);
    }

    /**
     * Gagal menemukan feed tetap menandai pencarian selesai.
     *
     * Kolom itulah yang membedakan "masih diantrekan" dari "sudah dicari,
     * memang tidak ada". Tanpa penandaan ini daftar media akan selamanya
     * menampilkan "Mencari RSS" untuk situs yang jelas tidak punya feed.
     */
    public function test_feed_tidak_ketemu_tetap_menandai_pencarian_selesai(): void
    {
        $media = $this->media();

        $penemu = $this->createMock(PenemuFeed::class);
        $penemu->method('cari')->willReturn(null);

        (new TemukanFeedMedia($media->id))->handle($penemu);

        $this->assertSame(0, SumberFeed::withoutGlobalScopes()->count());
        $this->assertNotNull($media->fresh()->feed_dicari_at);
    }
}
