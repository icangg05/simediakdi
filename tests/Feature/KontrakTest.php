<?php

namespace Tests\Feature;

use App\Enums\StatusVerifikasi;
use App\Models\Artikel;
use App\Models\Kontrak;
use App\Models\Media;
use App\Models\Pemuatan;
use App\Models\User;
use App\Services\Kontrak\PencocokPemuatan;
use App\Support\Waktu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KontrakTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);
    }

    private function kontrak(array $tambahan = []): Kontrak
    {
        return Kontrak::create([
            'media_id' => $this->media->id,
            'judul' => 'Publikasi Triwulan I',
            'jenis' => 'publikasi',
            'tanggal_mulai' => now()->subDays(10)->toDateString(),
            'tanggal_akhir' => now()->addDays(20)->toDateString(),
            'target_pemuatan' => 10,
            'status' => 'aktif',
            ...$tambahan,
        ]);
    }

    private function artikel(?Media $media = null, ?string $tanggal = null): Artikel
    {
        static $n = 0;
        $n++;

        return Artikel::withoutGlobalScopes()->create([
            'media_id' => ($media ?? $this->media)->id,
            'judul' => "Berita {$n}",
            'url' => "https://kp.test/{$n}",
            'url_kanonik' => "https://kp.test/{$n}",
            'isi' => 'Isi.',
            'diambil_at' => $tanggal ? Waktu::awalHari($tanggal)->addHours(9) : now()->subDays(2),
            'status_proses' => 'selesai',
        ]);
    }

    public function test_artikel_dalam_periode_dicocokkan_dan_langsung_terverifikasi(): void
    {
        $this->artikel();
        $this->artikel();

        $kontrak = $this->kontrak();
        $baru = app(PencocokPemuatan::class)->cocokkan($kontrak);

        $this->assertSame(2, $baru);
        $this->assertSame(2, Pemuatan::withoutGlobalScopes()->count());

        $pemuatan = Pemuatan::withoutGlobalScopes()->first();

        // Sistem sendiri yang menemukannya; tidak ada klaim yang perlu diperiksa.
        $this->assertSame(StatusVerifikasi::Terverifikasi, $pemuatan->status_verifikasi);
        $this->assertSame('otomatis', $pemuatan->sumber_catatan);
    }

    public function test_artikel_salinan_tetap_dihitung_sebagai_pemuatan(): void
    {
        $asli = $this->artikel();

        $salinan = $this->artikel();
        $salinan->update([
            'status_dedup' => 'salinan',
            'artikel_induk_id' => $asli->id,
        ]);

        $baru = app(PencocokPemuatan::class)->cocokkan($this->kontrak());

        // Rilis yang sama dimuat dua kali adalah satu isu, tetapi dua
        // pemuatan. Menyaringnya membuat media kehilangan realisasi kontrak
        // atas halaman yang benar-benar mereka terbitkan.
        $this->assertSame(2, $baru);
    }

    public function test_artikel_media_lain_tidak_ikut_tercatat(): void
    {
        $lain = Media::create(['nama' => 'Lain', 'slug' => 'lain', 'domain' => 'lain.test']);
        $this->artikel($lain);
        $this->artikel();

        app(PencocokPemuatan::class)->cocokkan($this->kontrak());

        $this->assertSame(1, Pemuatan::withoutGlobalScopes()->count());
    }

    public function test_artikel_di_luar_periode_kontrak_tidak_tercatat(): void
    {
        $this->artikel(tanggal: now()->subDays(60)->toDateString());
        $this->artikel();

        app(PencocokPemuatan::class)->cocokkan($this->kontrak());

        $this->assertSame(1, Pemuatan::withoutGlobalScopes()->count());
    }

    /** Pencocokan hanya untuk kontrak aktif, draft dan batal tidak dihitung. */
    public function test_kontrak_belum_aktif_tidak_dicocokkan(): void
    {
        $this->artikel();

        $this->assertSame(0, app(PencocokPemuatan::class)->cocokkan($this->kontrak(['status' => 'draft'])));
        $this->assertSame(0, Pemuatan::withoutGlobalScopes()->count());
    }

    public function test_mencocokkan_dua_kali_tidak_menggandakan_pemuatan(): void
    {
        $this->artikel();

        $kontrak = $this->kontrak();
        $pencocok = app(PencocokPemuatan::class);

        $pencocok->cocokkan($kontrak);
        $kedua = $pencocok->cocokkan($kontrak);

        $this->assertSame(0, $kedua);
        $this->assertSame(1, Pemuatan::withoutGlobalScopes()->count());
    }

    public function test_progres_menghitung_realisasi_terhadap_target(): void
    {
        foreach (range(1, 4) as $i) {
            $this->artikel();
        }

        $kontrak = $this->kontrak(['target_pemuatan' => 8]);
        $pencocok = app(PencocokPemuatan::class);
        $pencocok->cocokkan($kontrak);

        $progres = $pencocok->progres($kontrak);

        $this->assertSame(4, $progres['terverifikasi']);
        $this->assertSame(8, $progres['target']);
        $this->assertSame(50.0, $progres['persen']);
    }

    /**
     * Tertinggal dibandingkan terhadap waktu yang sudah berjalan, bukan sekadar
     * belum penuh. Kontrak yang baru mulai wajar masih jauh dari target.
     */
    public function test_kontrak_yang_baru_mulai_tidak_dianggap_tertinggal(): void
    {
        $kontrak = $this->kontrak([
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'tanggal_akhir' => now()->addDays(89)->toDateString(),
            'target_pemuatan' => 90,
        ]);

        $this->assertFalse(app(PencocokPemuatan::class)->progres($kontrak)['tertinggal']);
    }

    public function test_kontrak_yang_hampir_habis_tanpa_realisasi_ditandai_tertinggal(): void
    {
        $kontrak = $this->kontrak([
            'tanggal_mulai' => now()->subDays(80)->toDateString(),
            'tanggal_akhir' => now()->addDays(10)->toDateString(),
            'target_pemuatan' => 90,
        ]);

        $this->assertTrue(app(PencocokPemuatan::class)->progres($kontrak)['tertinggal']);
    }

    public function test_menyimpan_kontrak_langsung_mencocokkan_artikel_yang_sudah_ada(): void
    {
        $this->artikel();
        $this->artikel();

        $this->actingAs($this->admin)->post('/admin/kontrak', [
            'media_id' => $this->media->id,
            'judul' => 'Kontrak Baru',
            'jenis' => 'publikasi',
            'tanggal_mulai' => now()->subDays(10)->toDateString(),
            'tanggal_akhir' => now()->addDays(20)->toDateString(),
            'status' => 'aktif',
            'target_pemuatan' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Pemuatan::withoutGlobalScopes()->count());
    }

    public function test_tanggal_akhir_sebelum_tanggal_mulai_ditolak(): void
    {
        $this->actingAs($this->admin)->post('/admin/kontrak', [
            'media_id' => $this->media->id,
            'judul' => 'Kontrak Terbalik',
            'jenis' => 'publikasi',
            'tanggal_mulai' => now()->toDateString(),
            'tanggal_akhir' => now()->subDays(5)->toDateString(),
            'status' => 'draft',
        ])->assertSessionHasErrors('tanggal_akhir');
    }

    public function test_pengguna_media_hanya_melihat_kontrak_medianya(): void
    {
        $lain = Media::create(['nama' => 'Lain', 'slug' => 'lain', 'domain' => 'lain.test']);

        $this->kontrak();
        $this->kontrak(['media_id' => $lain->id, 'judul' => 'Kontrak Media Lain']);

        $this->actingAs(User::factory()->media($this->media)->create());

        $this->assertSame(1, Kontrak::count());
        $this->assertSame('Publikasi Triwulan I', Kontrak::first()->judul);
    }
}
