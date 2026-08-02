<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\SumberFeed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalikotaTidakBisaMenulisTest extends TestCase
{
    use RefreshDatabase;

    private User $walikota;

    private Media $media;

    private SumberFeed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->media = Media::create(['nama' => 'Media A', 'slug' => 'media-a', 'domain' => 'a.test']);
        $this->feed = SumberFeed::create([
            'media_id' => $this->media->id, 'nama' => 'Feed A', 'tipe' => 'rss', 'url' => 'https://a.test/feed',
        ]);

        $this->walikota = User::factory()->walikota()->create();
    }

    public function test_menolak_tulisan_ke_rute_admin(): void
    {
        // Id diambil dari baris nyata: kalau route model binding 404 lebih dulu,
        // tes akan lulus tanpa pernah menyentuh middleware yang diuji.
        $rute = [
            ['POST', '/admin/media'],
            ['PUT', "/admin/media/{$this->media->id}"],
            ['DELETE', "/admin/media/{$this->media->id}"],
            ['POST', '/admin/sumber-feed'],
            ['PUT', "/admin/sumber-feed/{$this->feed->id}"],
            ['DELETE', "/admin/sumber-feed/{$this->feed->id}"],
        ];

        foreach ($rute as [$metode, $url]) {
            $this->actingAs($this->walikota)
                ->call($metode, $url, ['nama' => 'Coba', 'jenis' => 'online', 'tier' => 'lokal'])
                ->assertForbidden("$metode $url seharusnya ditolak untuk peran walikota");
        }
    }

    public function test_masih_bisa_membaca_halaman_admin(): void
    {
        $this->actingAs($this->walikota)
            ->get('/admin/media')
            ->assertOk();
    }

    public function test_superadmin_tetap_bisa_menulis(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/admin/media', ['nama' => 'Media Baru', 'jenis' => 'online', 'tier' => 'lokal'])
            ->assertRedirect('/admin/media');

        $this->assertTrue(Media::where('nama', 'Media Baru')->exists());
    }

    public function test_walikota_tetap_bisa_keluar_dari_sesinya(): void
    {
        $this->actingAs($this->walikota)
            ->post('/logout')
            ->assertRedirect();
    }
}
