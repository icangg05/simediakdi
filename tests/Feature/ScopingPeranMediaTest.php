<?php

namespace Tests\Feature;

use App\Enums\PeranPengguna;
use App\Enums\StatusVerifikasi;
use App\Models\Artikel;
use App\Models\Kontrak;
use App\Models\Media;
use App\Models\Pemuatan;
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

    /** Satu artikel, satu kontrak, dan satu pemuatan untuk masing-masing media. */
    private function siapkanDataDuaMedia(): void
    {
        foreach ([['A', $this->mediaA], ['B', $this->mediaB]] as [$tanda, $media]) {
            Artikel::withoutGlobalScopes()->create([
                'media_id' => $media->id,
                'judul' => "Berita {$tanda}",
                'url' => "https://{$media->domain}/berita",
                'url_kanonik' => "https://{$media->domain}/berita",
                'diambil_at' => now(),
                'status_proses' => 'selesai',
            ]);

            $kontrak = Kontrak::withoutGlobalScopes()->create([
                'media_id' => $media->id,
                'judul' => "Kontrak {$tanda}",
                'jenis' => 'publikasi',
                'status' => 'aktif',
                'tanggal_mulai' => now()->subDays(10)->toDateString(),
                'tanggal_akhir' => now()->addDays(50)->toDateString(),
                'target_pemuatan' => 10,
            ]);

            Pemuatan::withoutGlobalScopes()->create([
                'kontrak_id' => $kontrak->id,
                'media_id' => $media->id,
                'url' => "https://{$media->domain}/pemuatan",
                'judul' => "Pemuatan {$tanda}",
                'tanggal_muat' => now()->toDateString(),
                'sumber_catatan' => 'otomatis',
                'status_verifikasi' => StatusVerifikasi::Terverifikasi,
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
     * Kebocoran di sini berarti satu media membaca realisasi kontrak dan
     * daftar pemberitaan pesaingnya.
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
            ->get('/portal/kontrak')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('kontrak', 1)
                ->where('kontrak.0.judul', 'Kontrak A')
                ->has('pemuatan', 1)
                ->where('pemuatan.0.judul', 'Pemuatan A'));
    }

    public function test_pemuatan_dan_kontrak_media_lain_tidak_terlihat(): void
    {
        $this->siapkanDataDuaMedia();

        $this->actingAs($this->penggunaA);

        $this->assertSame(1, Kontrak::count());
        $this->assertSame(1, Pemuatan::count());
        $this->assertSame('Kontrak A', Kontrak::first()->judul);
    }

    /**
     * Melaporkan pemuatan ke kontrak media lain berarti menyedot realisasi
     * kontrak orang. Ditolak sebelum menyentuh database.
     */
    public function test_tidak_bisa_melapor_ke_kontrak_media_lain(): void
    {
        $this->siapkanDataDuaMedia();

        $kontrakB = Kontrak::withoutGlobalScopes()->where('judul', 'Kontrak B')->firstOrFail();

        $this->actingAs($this->penggunaA)->post('/portal/lapor', [
            'kontrak_id' => $kontrakB->id,
            'baris' => [[
                'url' => 'https://a.test/berita-baru',
                'judul' => 'Berita baru',
                'tanggal' => now()->toDateString(),
            ]],
        ])->assertNotFound();

        $this->assertSame(2, Pemuatan::withoutGlobalScopes()->count());
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
