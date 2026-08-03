<?php

namespace Tests\Feature;

use App\Enums\PeranPengguna;
use App\Enums\StatusVerifikasi;
use App\Jobs\ArsipkanBuktiPemuatan;
use App\Models\Artikel;
use App\Models\Kontrak;
use App\Models\Media;
use App\Models\Pemuatan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Lapor pemuatan, dokumen 04 bagian C.6.
 *
 * Yang diuji di sini bukan tampilannya, melainkan batas-batas yang kalau jebol
 * berakibat pada uang: URL media lain yang diklaim sebagai pemuatan sendiri,
 * satu berita yang dihitung dua kali, dan laporan yang masuk tanpa verifikasi.
 */
class PortalLaporTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    private Kontrak $kontrak;

    private User $pic;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kendari-pos', 'domain' => 'kendaripos.test']);

        $this->kontrak = Kontrak::withoutGlobalScopes()->create([
            'media_id' => $this->media->id,
            'judul' => 'Publikasi 2026',
            'jenis' => 'publikasi',
            'status' => 'aktif',
            'tanggal_mulai' => now()->subDays(30)->toDateString(),
            'tanggal_akhir' => now()->addDays(60)->toDateString(),
            'target_pemuatan' => 20,
        ]);

        $this->pic = User::create([
            'name' => 'PIC Kendari Pos', 'email' => 'pic@kendaripos.test', 'password' => 'rahasia123',
            'peran' => PeranPengguna::Media, 'media_id' => $this->media->id, 'email_verified_at' => now(),
        ]);
    }

    /**
     * F-50. Ini batas antar media, bukan sekadar bantuan pengisian form, jadi
     * ditegakkan saat menyimpan dan bukan hanya saat pratinjau.
     */
    public function test_url_dari_domain_media_lain_ditolak(): void
    {
        $this->actingAs($this->pic)->post('/portal/lapor', [
            'kontrak_id' => $this->kontrak->id,
            'baris' => [[
                'url' => 'https://mediapesaing.test/berita-a',
                'judul' => 'Berita milik media lain',
                'tanggal' => now()->toDateString(),
            ]],
        ]);

        $this->assertSame(0, Pemuatan::withoutGlobalScopes()->count());
    }

    public function test_subdomain_media_sendiri_diterima(): void
    {
        $this->actingAs($this->pic)->post('/portal/lapor', [
            'kontrak_id' => $this->kontrak->id,
            'baris' => [[
                'url' => 'https://daerah.kendaripos.test/berita-b',
                'judul' => 'Berita dari subdomain',
                'tanggal' => now()->toDateString(),
            ]],
        ]);

        $this->assertSame(1, Pemuatan::withoutGlobalScopes()->count());
    }

    /** Laporan media selalu menunggu manusia. Yang langsung sah hanya temuan crawler. */
    public function test_laporan_media_berstatus_menunggu_verifikasi(): void
    {
        $this->actingAs($this->pic)->post('/portal/lapor', [
            'kontrak_id' => $this->kontrak->id,
            'baris' => [[
                'url' => 'https://kendaripos.test/berita-c',
                'judul' => 'Berita C',
                'tanggal' => now()->toDateString(),
            ]],
        ]);

        $pemuatan = Pemuatan::withoutGlobalScopes()->first();

        $this->assertSame(StatusVerifikasi::Menunggu, $pemuatan->status_verifikasi);
        $this->assertSame('laporan_media', $pemuatan->sumber_catatan);
        $this->assertSame($this->pic->id, $pemuatan->dilaporkan_oleh);
    }

    /** F-52: bukti diambil di latar belakang, media tidak menunggunya. */
    public function test_pengarsipan_bukti_dijadwalkan_di_antrean(): void
    {
        $this->actingAs($this->pic)->post('/portal/lapor', [
            'kontrak_id' => $this->kontrak->id,
            'baris' => [[
                'url' => 'https://kendaripos.test/berita-d',
                'judul' => 'Berita D',
                'tanggal' => now()->toDateString(),
            ]],
        ]);

        Queue::assertPushed(ArsipkanBuktiPemuatan::class);
    }

    /**
     * Artikel yang sudah ditemukan crawler tidak boleh dihitung dua kali.
     * Kalau ini jebol, realisasi kontrak yang dibayar bisa dua kali lipat dari
     * pemuatan yang sebenarnya terjadi.
     */
    public function test_url_yang_sudah_tercatat_tidak_membuat_baris_kedua(): void
    {
        Pemuatan::withoutGlobalScopes()->create([
            'kontrak_id' => $this->kontrak->id,
            'media_id' => $this->media->id,
            'url' => 'https://kendaripos.test/berita-e',
            'judul' => 'Berita E',
            'tanggal_muat' => now()->toDateString(),
            'sumber_catatan' => 'otomatis',
            'status_verifikasi' => StatusVerifikasi::Terverifikasi,
        ]);

        $this->actingAs($this->pic)->post('/portal/lapor', [
            'kontrak_id' => $this->kontrak->id,
            'baris' => [[
                'url' => 'https://kendaripos.test/berita-e?utm_source=wa',
                'judul' => 'Berita E',
                'tanggal' => now()->toDateString(),
            ]],
        ]);

        $this->assertSame(1, Pemuatan::withoutGlobalScopes()->count());
    }

    /** F-48: daftar yang sudah tercatat otomatis tampil sebelum form. */
    public function test_halaman_lapor_menampilkan_yang_sudah_tercatat_otomatis(): void
    {
        Pemuatan::withoutGlobalScopes()->create([
            'kontrak_id' => $this->kontrak->id,
            'media_id' => $this->media->id,
            'url' => 'https://kendaripos.test/berita-f',
            'judul' => 'Sudah ketemu crawler',
            'tanggal_muat' => now()->toDateString(),
            'sumber_catatan' => 'otomatis',
            'status_verifikasi' => StatusVerifikasi::Terverifikasi,
        ]);

        $this->actingAs($this->pic)
            ->get('/portal/lapor')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('sudahOtomatis', 1));
    }

    /** Tanggal muat di masa depan hampir selalu salah ketik, bukan laporan sah. */
    public function test_tanggal_muat_di_masa_depan_ditolak(): void
    {
        $this->actingAs($this->pic)->post('/portal/lapor', [
            'kontrak_id' => $this->kontrak->id,
            'baris' => [[
                'url' => 'https://kendaripos.test/berita-g',
                'judul' => 'Berita G',
                'tanggal' => now()->addDay()->toDateString(),
            ]],
        ])->assertSessionHasErrors('baris.0.tanggal');
    }

    /**
     * Portal tidak boleh membocorkan sentimen ke media (dokumen 01 bagian 8).
     * Diuji terhadap bentuk resource-nya, bukan terhadap tampilan, karena
     * kebocoran akan terjadi lewat data yang terkirim.
     */
    public function test_berita_saya_tidak_memuat_field_sentimen(): void
    {
        Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id, 'judul' => 'Berita uji',
            'url' => 'https://kendaripos.test/uji', 'url_kanonik' => 'https://kendaripos.test/uji',
            'diambil_at' => now(), 'status_proses' => 'selesai',
        ]);

        $this->actingAs($this->pic)
            ->get('/portal/berita')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('artikel.data', 1)
                ->missing('artikel.data.0.label_efektif')
                ->missing('artikel.data.0.analisis_sentimen')
                ->missing('artikel.data.0.isi'));
    }
}
