<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Models\User;
use App\Services\Agregasi\RingkasanHarian;
use App\Support\Waktu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel eksekutif seluruhnya read-only dan dilihat pimpinan.
 *
 * Yang diuji di sini bukan tampilannya, tapi bahwa angkanya benar-benar terbaca
 * dari tabel ringkasan dan pilihan rentang tidak hilang antar halaman.
 */
class HalamanEksekutifTest extends TestCase
{
    use RefreshDatabase;

    private KonteksPantauan $utama;

    private User $walikota;

    protected function setUp(): void
    {
        parent::setUp();

        $this->walikota = User::factory()->walikota()->create();
        $this->utama = KonteksPantauan::create(['nama' => 'Pemkot', 'slug' => 'pemkot', 'utama' => true]);

        $media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        foreach ([LabelSentimen::Negatif, LabelSentimen::Positif, LabelSentimen::Positif] as $n => $label) {
            $artikel = Artikel::withoutGlobalScopes()->create([
                'media_id' => $media->id,
                'judul' => "Berita {$n}",
                'url' => "https://kp.test/{$n}",
                'url_kanonik' => "https://kp.test/{$n}",
                'isi' => 'Isi berita.',
                'diambil_at' => Waktu::awalHariIni()->addHours(9),
                'status_proses' => 'selesai',
            ]);

            AnalisisSentimen::create([
                'artikel_id' => $artikel->id,
                'konteks_pantauan_id' => $this->utama->id,
                'relevan' => true,
                'label_model' => $label,
                'keyakinan' => 0.95,
            ]);
        }

        app(RingkasanHarian::class)->hitung(Waktu::tanggalWita(now()));
    }

    public function test_semua_halaman_eksekutif_terbuka_untuk_walikota(): void
    {
        $this->actingAs($this->walikota);

        foreach (['/eksekutif', '/eksekutif/sentimen', '/eksekutif/isu', '/eksekutif/media', '/eksekutif/berita'] as $url) {
            $this->get($url)->assertOk("Halaman {$url} tidak mengembalikan 200.");
        }
    }

    public function test_pengguna_media_tidak_bisa_membuka_panel_eksekutif(): void
    {
        $media = Media::first();

        $this->actingAs(User::factory()->media($media)->create())
            ->get('/eksekutif')
            ->assertForbidden();
    }

    /** Angka KPI harus cocok saat dihitung ulang manual dari tabel artikel. */
    public function test_kpi_membaca_angka_dari_tabel_ringkasan(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif')
            ->assertInertia(fn ($page) => $page
                ->where('kpi.artikel', 3)
                ->where('kpi.negatif', 1)
                ->where('kpi.positif', 2)
                // 1 dari 3 berlabel, dibulatkan satu desimal.
                ->where('kpi.negatif_persen', 33.3)
                ->where('kpi.media_aktif', 1));
    }

    public function test_rentang_tanggal_dibaca_dari_query_dan_dibawa_ke_tampilan(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif?dari=2026-07-01&sampai=2026-07-31')
            ->assertInertia(fn ($page) => $page
                ->where('periode.dari', '2026-07-01')
                ->where('periode.sampai', '2026-07-31'));
    }

    /** Rentang terbalik biasanya salah ketik, bukan permintaan sungguhan. */
    public function test_rentang_terbalik_ditukar_bukan_ditolak(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif?dari=2026-07-31&sampai=2026-07-01')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('periode.dari', '2026-07-01')
                ->where('periode.sampai', '2026-07-31'));
    }

    public function test_tanggal_ngawur_jatuh_ke_rentang_bawaan_tujuh_hari(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif?dari=bukan-tanggal&sampai=juga-bukan')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'periode.sampai',
                Waktu::tanggalWita(now()),
            ));
    }

    /** Satu permintaan tidak boleh menyapu bertahun-tahun data. */
    public function test_rentang_sangat_panjang_dipangkas(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif?dari=2000-01-01&sampai=2026-08-03')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('periode.dari', '2025-08-02'));
    }

    /**
     * Kartu peringatan tidak dirender saat tidak ada isinya, kartu kosong
     * menghabiskan ruang layar yang berharga.
     */
    public function test_kartu_peringatan_null_saat_tidak_ada_alert(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif')
            ->assertInertia(fn ($page) => $page->where('peringatan', null));
    }

    public function test_konteks_utama_dipilih_saat_tidak_diminta(): void
    {
        $this->actingAs($this->walikota)
            ->get('/eksekutif')
            ->assertInertia(fn ($page) => $page->where('konteksId', $this->utama->id));
    }
}
