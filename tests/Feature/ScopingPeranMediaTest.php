<?php

namespace Tests\Feature;

use App\Enums\PeranPengguna;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scoping peran media adalah satu-satunya fitur yang, kalau bocor, menimbulkan
 * masalah dengan pihak luar dan bukan hanya ketidaknyamanan internal.
 */
class ScopingPeranMediaTest extends TestCase
{
    use RefreshDatabase;

    private Media $mediaA;

    private Media $mediaB;

    private SumberFeed $feedA;

    private SumberFeed $feedB;

    private User $penggunaA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaA = Media::create(['nama' => 'Media A', 'slug' => 'media-a', 'domain' => 'a.test']);
        $this->mediaB = Media::create(['nama' => 'Media B', 'slug' => 'media-b', 'domain' => 'b.test']);

        $this->feedA = SumberFeed::create([
            'media_id' => $this->mediaA->id, 'nama' => 'Feed A', 'tipe' => 'rss', 'url' => 'https://a.test/feed',
        ]);
        $this->feedB = SumberFeed::create([
            'media_id' => $this->mediaB->id, 'nama' => 'Feed B', 'tipe' => 'rss', 'url' => 'https://b.test/feed',
        ]);

        $this->penggunaA = User::create([
            'name' => 'PIC A', 'email' => 'a@test.id', 'password' => 'rahasia123',
            'peran' => PeranPengguna::Media, 'media_id' => $this->mediaA->id, 'email_verified_at' => now(),
        ]);
    }

    public function test_sumber_feed_media_lain_tidak_terlihat(): void
    {
        $this->actingAs($this->penggunaA);

        $terlihat = SumberFeed::pluck('id')->all();

        $this->assertContains($this->feedA->id, $terlihat);
        $this->assertNotContains($this->feedB->id, $terlihat);
    }

    public function test_media_lain_tidak_terlihat(): void
    {
        $this->actingAs($this->penggunaA);

        $terlihat = Media::pluck('id')->all();

        $this->assertContains($this->mediaA->id, $terlihat);
        $this->assertNotContains($this->mediaB->id, $terlihat);
    }

    public function test_pengguna_media_tidak_bisa_membuka_panel_admin(): void
    {
        $this->actingAs($this->penggunaA)
            ->get('/admin/media')
            ->assertForbidden();
    }

    public function test_superadmin_melihat_semua_baris(): void
    {
        $superadmin = User::create([
            'name' => 'Admin', 'email' => 'admin@test.id', 'password' => 'rahasia123',
            'peran' => PeranPengguna::Superadmin, 'email_verified_at' => now(),
        ]);

        $this->actingAs($superadmin);

        $this->assertSame(2, Media::count());
        $this->assertSame(2, SumberFeed::count());
    }
}
