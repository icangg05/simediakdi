<?php

namespace Tests\Feature;

use App\Enums\StatusVerifikasi;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\AturanAlert;
use App\Models\Entitas;
use App\Models\KonteksPantauan;
use App\Models\Kontrak;
use App\Models\Media;
use App\Models\Pemuatan;
use App\Models\SumberFeed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WalikotaTidakBisaMenulisTest extends TestCase
{
    use RefreshDatabase;

    private User $walikota;

    private Media $media;

    /** @var array<string, int> */
    private array $parameter = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->media = Media::create(['nama' => 'Media A', 'slug' => 'media-a', 'domain' => 'a.test']);

        $feed = SumberFeed::create([
            'media_id' => $this->media->id, 'nama' => 'Feed A', 'tipe' => 'rss', 'url' => 'https://a.test/feed',
        ]);

        $konteks = KonteksPantauan::create(['nama' => 'Pemkot', 'slug' => 'pemkot', 'aktif' => true]);

        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id, 'judul' => 'Berita', 'url' => 'https://a.test/b',
            'url_kanonik' => 'https://a.test/b', 'diambil_at' => now(), 'status_proses' => 'selesai',
        ]);

        $analisis = AnalisisSentimen::create([
            'artikel_id' => $artikel->id, 'konteks_pantauan_id' => $konteks->id, 'relevan' => true,
            'label_model' => 'netral', 'keyakinan' => 0.9, 'perlu_review' => false,
            'model_versi' => 'uji', 'dianalisis_at' => now(),
        ]);

        $kontrak = Kontrak::withoutGlobalScopes()->create([
            'media_id' => $this->media->id, 'judul' => 'Kontrak', 'jenis' => 'publikasi', 'status' => 'aktif',
            'tanggal_mulai' => now()->subDays(5)->toDateString(),
            'tanggal_akhir' => now()->addDays(30)->toDateString(),
        ]);

        $pemuatan = Pemuatan::withoutGlobalScopes()->create([
            'kontrak_id' => $kontrak->id, 'media_id' => $this->media->id, 'url' => 'https://a.test/p',
            'tanggal_muat' => now()->toDateString(), 'sumber_catatan' => 'laporan_media',
            'status_verifikasi' => StatusVerifikasi::Menunggu,
        ]);

        $alert = AturanAlert::create([
            'nama' => 'Uji', 'jenis' => 'sumber_mati', 'kondisi' => [], 'jendela_jam' => 6,
            'jeda_minimal_jam' => 6, 'kanal' => 'telegram', 'penerima' => [], 'aktif' => true,
        ]);

        $entitas = Entitas::create([
            'nama' => 'Wali Kota', 'nama_normal' => 'wali kota', 'jenis' => 'orang', 'alias' => [],
        ]);

        $this->walikota = User::factory()->walikota()->create();

        // Id diambil dari baris nyata: kalau route model binding 404 lebih dulu,
        // tes akan lulus tanpa pernah menyentuh middleware yang diuji.
        $this->parameter = [
            // Route::resource('media') menunggalkan "media" jadi "{medium}".
            'medium' => $this->media->id,
            'sumberFeed' => $feed->id,
            'konteks' => $konteks->id,
            'analisis' => $analisis->id,
            'kontrak' => $kontrak->id,
            'pemuatan' => $pemuatan->id,
            'alert' => $alert->id,
            'entitas' => $entitas->id,
            'pengguna' => $this->walikota->id,
        ];
    }

    /**
     * Seluruh rute tulis di grup admin, dibaca dari router.
     *
     * Sengaja tidak berupa daftar yang ditulis tangan. Daftar tangan berhenti
     * mencerminkan aplikasi pada rute pertama yang lupa ditambahkan, dan
     * lubangnya persis di fitur terbaru yang paling belum teruji. Parameter
     * yang belum dikenal membuat tes gagal, bukan dilewati, supaya resource
     * baru memaksa perakitan datanya ditulis di setUp.
     */
    public function test_menolak_tulisan_ke_rute_admin(): void
    {
        $diuji = 0;

        foreach (Route::getRoutes() as $rute) {
            $metode = collect($rute->methods())->intersect(['POST', 'PUT', 'PATCH', 'DELETE'])->first();

            if ($metode === null || ! str_starts_with($rute->uri(), 'admin/')) {
                continue;
            }

            $url = '/'.$rute->uri();

            foreach ($rute->parameterNames() as $nama) {
                $this->assertArrayHasKey(
                    $nama,
                    $this->parameter,
                    "Rute {$rute->uri()} memakai parameter {{$nama}} yang belum punya data uji. "
                    .'Tambahkan barisnya di setUp, jangan lewati rutenya.',
                );

                $url = str_replace('{'.$nama.'}', (string) $this->parameter[$nama], $url);
            }

            $this->actingAs($this->walikota)
                ->call($metode, $url, ['nama' => 'Coba', 'jenis' => 'online', 'tier' => 'lokal'])
                ->assertForbidden("{$metode} {$url} seharusnya ditolak untuk peran walikota");

            $diuji++;
        }

        // Penjaga terhadap tes yang lulus karena tidak menemukan rute apa pun.
        $this->assertGreaterThan(20, $diuji, 'Rute tulis admin yang teruji terlalu sedikit.');
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
