<?php

namespace Tests\Feature;

use App\Models\Artikel;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Penunjuk kemajuan ekstraksi di dashboard admin.
 *
 * Dibaca dari `artikel.status_proses`, bukan dari isi antrean Redis. Antrean
 * Redis yang kosong bukan berarti pekerjaannya selesai, bisa juga berarti
 * job-nya hilang, dan penunjuk yang berbohong lebih buruk daripada tidak ada.
 */
class EkstraksiDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);
    }

    private function artikel(string $status): Artikel
    {
        return Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id,
            'judul' => 'Berita',
            'url' => 'https://kp.test/'.uniqid(),
            'url_kanonik' => 'https://kp.test/'.uniqid(),
            'diambil_at' => now(),
            'status_proses' => $status,
        ]);
    }

    private function dashboard(): TestResponse
    {
        return $this->actingAs(User::factory()->create(['peran' => 'superadmin']))->get('/admin');
    }

    public function test_menghitung_artikel_mentah_dan_kemajuan_hari_ini(): void
    {
        $this->artikel('mentah');
        $this->artikel('mentah');
        $this->artikel('isi_diambil');
        $this->artikel('selesai');

        $this->dashboard()
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ekstraksi.mentah', 2)
                ->where('ekstraksi.masuk_hari_ini', 4)
                // Dua sisanya sudah lewat tahap ekstraksi, apa pun status
                // lanjutannya.
                ->where('ekstraksi.diekstrak_hari_ini', 2)
                ->where('ekstraksi.persen', 50)
                ->where('ekstraksi.belum_klasifikasi', 1),
            );
    }

    /**
     * Laju diukur dari artikel yang baru saja mencapai `isi_diambil`. Satu
     * artikel dalam jendela sepuluh menit berarti 0,1 per menit, dan sisa satu
     * artikel mentah berarti sepuluh menit lagi.
     */
    public function test_estimasi_selesai_dihitung_dari_laju_terukur(): void
    {
        $this->artikel('mentah');
        $this->artikel('isi_diambil');

        $this->dashboard()->assertInertia(fn ($page) => $page
            ->where('ekstraksi.laju_per_menit', 0.1)
            ->whereNot('ekstraksi.estimasi_selesai_at', null),
        );
    }

    /**
     * Laju nol tidak boleh berubah menjadi pembagian dengan nol, dan tidak boleh
     * pula dipoles menjadi angka karangan. Tidak tahu ditulis sebagai tidak tahu.
     */
    public function test_tanpa_laju_estimasi_dibiarkan_kosong(): void
    {
        $this->artikel('mentah');

        $this->dashboard()->assertInertia(fn ($page) => $page
            ->where('ekstraksi.laju_per_menit', 0)
            ->where('ekstraksi.estimasi_selesai_at', null),
        );
    }

    public function test_tanpa_artikel_sama_sekali_tidak_membagi_dengan_nol(): void
    {
        $this->dashboard()
            ->assertOk()
            // 100 tanpa desimal: json_encode memangkas `.0` dari bilangan
            // bulat, jadi assertion float akan selalu gagal di sini.
            ->assertInertia(fn ($page) => $page->where('ekstraksi.persen', 100));
    }
}
