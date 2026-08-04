<?php

namespace Tests\Feature;

use App\Models\Artikel;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penunjuk kemajuan antrean di dashboard admin.
 *
 * Dibaca dari `artikel.status_proses`, bukan dari isi antrean Redis. Antrean
 * Redis yang kosong bukan berarti pekerjaannya selesai, bisa juga berarti
 * job-nya hilang, dan penunjuk yang berbohong lebih buruk daripada tidak ada.
 */
class AntreanDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);
    }

    private function artikel(string $status): void
    {
        Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id,
            'judul' => 'Berita',
            'url' => 'https://kp.test/'.uniqid(),
            'url_kanonik' => 'https://kp.test/'.uniqid(),
            'diambil_at' => now(),
            'status_proses' => $status,
        ]);
    }

    public function test_menghitung_yang_menunggu_dan_yang_tuntas(): void
    {
        $this->artikel('mentah');
        $this->artikel('isi_diambil');
        $this->artikel('dianalisis');
        $this->artikel('dianalisis');
        $this->artikel('selesai');
        $this->artikel('tidak_relevan');
        $this->artikel('perlu_review');

        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('antrean.menunggu', 4)
                // Perlu review dan tidak relevan sudah tuntas diproses sistem,
                // walau yang pertama masih menunggu manusia.
                ->where('antrean.tuntas', 3)
                ->where('antrean.total', 7)
                ->where('antrean.tahap.2.jumlah', 2),
            );
    }

    public function test_antrean_kosong_menghasilkan_seratus_persen(): void
    {
        $this->artikel('selesai');

        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->get('/admin')
            ->assertInertia(fn ($page) => $page
                ->where('antrean.menunggu', 0)
                // 100 tanpa desimal: json_encode memangkas `.0` dari bilangan
                // bulat, jadi assertion float akan selalu gagal di sini.
                ->where('antrean.persen', 100),
            );
    }

    public function test_tanpa_artikel_sama_sekali_tidak_membagi_dengan_nol(): void
    {
        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('antrean.persen', 100));
    }

    public function test_salinan_tidak_ikut_dihitung_sebagai_pekerjaan(): void
    {
        $this->artikel('selesai');

        $salinan = Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id,
            'judul' => 'Salinan',
            'url' => 'https://kp.test/salinan',
            'url_kanonik' => 'https://kp.test/salinan',
            'diambil_at' => now(),
            'status_proses' => 'mentah',
            'status_dedup' => 'salinan',
            'artikel_induk_id' => Artikel::withoutGlobalScopes()->first()->id,
        ]);

        // Salinan berhenti sebelum analisis, jadi menghitungnya sebagai
        // pekerjaan tertunda membuat penunjuk tidak pernah mencapai 100%.
        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->get('/admin')
            ->assertInertia(fn ($page) => $page->where('antrean.menunggu', 0));

        $this->assertSame('salinan', $salinan->status_dedup->value);
    }
}
