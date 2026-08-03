<?php

namespace Tests\Feature;

use App\Enums\PeranPengguna;
use App\Enums\StatusVerifikasi;
use App\Models\Kontrak;
use App\Models\Media;
use App\Models\Pemuatan;
use App\Models\User;
use App\Services\Kontrak\PencocokPemuatan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerifikasiPemuatanTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    private Kontrak $kontrak;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        $this->kontrak = Kontrak::withoutGlobalScopes()->create([
            'media_id' => $this->media->id, 'judul' => 'Publikasi', 'jenis' => 'publikasi', 'status' => 'aktif',
            'tanggal_mulai' => now()->subDays(10)->toDateString(),
            'tanggal_akhir' => now()->addDays(50)->toDateString(),
            'target_pemuatan' => 10,
        ]);
    }

    /**
     * Constraint database menegakkan aturan yang sama. Divalidasi juga di
     * controller supaya admin mendapat pesan yang bisa dibaca, bukan galat SQL.
     */
    public function test_penolakan_tanpa_alasan_ditolak(): void
    {
        $pemuatan = $this->laporan();

        $this->actingAs($this->admin)
            ->put("/admin/pemuatan/{$pemuatan->id}", ['status_verifikasi' => 'ditolak'])
            ->assertSessionHasErrors('alasan_penolakan');

        $this->assertSame(StatusVerifikasi::Menunggu, $pemuatan->refresh()->status_verifikasi);
    }

    public function test_alasan_penolakan_tersimpan_dan_terbaca_media(): void
    {
        $pemuatan = $this->laporan();

        $this->actingAs($this->admin)->put("/admin/pemuatan/{$pemuatan->id}", [
            'status_verifikasi' => 'ditolak',
            'alasan_penolakan' => 'Tautannya mengarah ke halaman kategori, bukan artikel.',
        ])->assertSessionHasNoErrors();

        $pic = User::create([
            'name' => 'PIC', 'email' => 'pic@kp.test', 'password' => 'rahasia123',
            'peran' => PeranPengguna::Media, 'media_id' => $this->media->id, 'email_verified_at' => now(),
        ]);

        $this->actingAs($pic)
            ->get('/portal/kontrak')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('ditolak', 1)
                ->where('ditolak.0.alasan_penolakan', 'Tautannya mengarah ke halaman kategori, bukan artikel.'));
    }

    /** Realisasi kontrak hanya menghitung yang terverifikasi. */
    public function test_laporan_baru_menambah_realisasi_setelah_diverifikasi(): void
    {
        $pemuatan = $this->laporan();
        $pencocok = app(PencocokPemuatan::class);

        $this->assertSame(0, $pencocok->progres($this->kontrak)['terverifikasi']);

        $this->actingAs($this->admin)->put("/admin/pemuatan/{$pemuatan->id}", [
            'status_verifikasi' => 'terverifikasi',
        ]);

        $this->assertSame(1, $pencocok->progres($this->kontrak->fresh())['terverifikasi']);
    }

    /** Yang ditemukan crawler sudah sah sejak dibuat, jadi tidak mengantre. */
    public function test_pemuatan_otomatis_tidak_masuk_antrean_verifikasi(): void
    {
        Pemuatan::withoutGlobalScopes()->create([
            'kontrak_id' => $this->kontrak->id, 'media_id' => $this->media->id,
            'url' => 'https://kp.test/otomatis', 'judul' => 'Dari crawler',
            'tanggal_muat' => now()->toDateString(), 'sumber_catatan' => 'otomatis',
            'status_verifikasi' => StatusVerifikasi::Terverifikasi,
        ]);

        $this->laporan();

        $this->actingAs($this->admin)
            ->get('/admin/pemuatan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('pemuatan.data', 1)
                ->where('pemuatan.data.0.sumber_catatan', 'laporan_media')
                ->where('jumlahMenunggu', 1));
    }

    /** Bukti disimpan di luar public/, jadi rutenya harus melewati middleware peran. */
    public function test_bukti_tidak_bisa_diambil_tanpa_peran_admin(): void
    {
        $pemuatan = $this->laporan();

        $pic = User::create([
            'name' => 'PIC', 'email' => 'pic2@kp.test', 'password' => 'rahasia123',
            'peran' => PeranPengguna::Media, 'media_id' => $this->media->id, 'email_verified_at' => now(),
        ]);

        $this->actingAs($pic)
            ->get("/admin/pemuatan/{$pemuatan->id}/bukti")
            ->assertForbidden();
    }

    private function laporan(): Pemuatan
    {
        return Pemuatan::withoutGlobalScopes()->create([
            'kontrak_id' => $this->kontrak->id,
            'media_id' => $this->media->id,
            'url' => 'https://kp.test/laporan',
            'judul' => 'Laporan media',
            'tanggal_muat' => now()->toDateString(),
            'sumber_catatan' => 'laporan_media',
            'status_verifikasi' => StatusVerifikasi::Menunggu,
        ]);
    }
}
