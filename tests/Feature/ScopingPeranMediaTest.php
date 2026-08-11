<?php

namespace Tests\Feature;

use App\Enums\PeranPengguna;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
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

    /**
     * Satu berita terpantau untuk masing-masing media.
     *
     * Harus sudah relevan dan berlabel, karena itu populasi yang ditampilkan
     * portal. Artikel mentah tidak akan muncul di halaman mana pun dan tesnya
     * akan lulus tanpa pernah menguji scoping.
     */
    private function siapkanDataDuaMedia(): void
    {
        foreach ([['A', $this->mediaA], ['B', $this->mediaB]] as [$tanda, $media]) {
            $artikel = Artikel::withoutGlobalScopes()->create([
                'media_id' => $media->id,
                'judul' => "Berita {$tanda}",
                'url' => "https://{$media->domain}/berita",
                'url_kanonik' => "https://{$media->domain}/berita",
                'diambil_at' => now(),
                'status_proses' => 'selesai',
            ]);

            AnalisisSentimen::create([
                'artikel_id' => $artikel->id, 'relevan' => true,
                'label_model' => 'netral', 'perlu_review' => false,
                'model_versi' => 'uji', 'dianalisis_at' => now(),
            ]);
        }
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

    /**
     * Portal media adalah satu-satunya bagian sistem yang dibuka pihak luar.
     * Kebocoran di sini berarti satu media membaca daftar pemberitaan
     * pesaingnya.
     */
    public function test_portal_hanya_menampilkan_data_media_sendiri(): void
    {
        $this->siapkanDataDuaMedia();

        $this->actingAs($this->penggunaA)
            ->get('/portal/berita')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('artikel.data', 1)
                ->where('artikel.data.0.judul', 'Berita A'));

        $this->actingAs($this->penggunaA)
            ->get('/portal/lapor')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sudahOtomatis', 1)
                ->where('sudahOtomatis.0.judul', 'Berita A'));
    }

    public function test_artikel_media_lain_tidak_terlihat(): void
    {
        $this->siapkanDataDuaMedia();

        $this->actingAs($this->penggunaA);

        $this->assertSame(1, Artikel::count());
        $this->assertSame('Berita A', Artikel::first()->judul);
    }

    /**
     * Menambahkan URL milik media lain berarti menyuntikkan berita ke arsip
     * atas nama orang. Tanpa antrean persetujuan, pemeriksaan domain inilah
     * satu-satunya yang menahannya, jadi ia ditegakkan sebelum menyentuh
     * database.
     */
    public function test_tidak_bisa_menambahkan_url_media_lain(): void
    {
        $this->siapkanDataDuaMedia();

        $this->actingAs($this->penggunaA)->post('/portal/lapor', [
            'baris' => [[
                'url' => 'https://b.test/berita-baru',
                'judul' => 'Berita milik B',
                'tanggal' => now()->toDateString(),
            ]],
        ]);

        $this->assertSame(2, Artikel::withoutGlobalScopes()->count());
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
