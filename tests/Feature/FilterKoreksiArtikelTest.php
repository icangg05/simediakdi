<?php

namespace Tests\Feature;

use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saringan Dikoreksi di halaman `/admin/artikel`.
 *
 * Koreksi manusia tersimpan di dua kolom terpisah, `relevan_manual` untuk
 * relevansi dan `label_manual` untuk sentimen, dan admin jarang mengisi
 * keduanya pada artikel yang sama. Artikel yang ditandai tidak relevan berhenti
 * sebelum sentimennya pernah dinilai, jadi saringan yang menuntut kedua kolom
 * terisi justru menyembunyikan koreksi yang paling sering dilakukan.
 */
class FilterKoreksiArtikelTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->media = Media::create([
            'nama' => 'Media Contoh',
            'slug' => 'media-contoh',
            'domain' => 'contoh.id',
            'aktif' => true,
        ]);
    }

    public function test_hanya_artikel_dengan_koreksi_manusia_yang_tersaring(): void
    {
        $relevansiDikoreksi = $this->artikel('Relevansi dikoreksi', ['relevan_manual' => true]);
        $sentimenDikoreksi = $this->artikel('Sentimen dikoreksi', ['label_manual' => 'positif']);
        $murniAi = $this->artikel('Murni AI', []);

        $judul = collect(
            $this->actingAs(User::factory()->create())
                ->get('/admin/artikel?koreksi=1')
                ->viewData('page')['props']['artikel']['data'],
        )->pluck('judul');

        $this->assertContains($relevansiDikoreksi->judul, $judul);
        $this->assertContains($sentimenDikoreksi->judul, $judul);
        $this->assertNotContains($murniAi->judul, $judul, 'Baris tanpa koreksi manusia tidak boleh ikut tersaring.');
    }

    /**
     * Angka pada tombol tahap ikut menyusut saat saringan menyala.
     *
     * Angka yang menjanjikan isi berbeda dari yang muncul setelah tombolnya
     * ditekan lebih buruk daripada tidak ada angka sama sekali.
     */
    public function test_angka_pada_tombol_tahap_ikut_disaring(): void
    {
        $this->artikel('Relevansi dikoreksi', ['relevan_manual' => true]);
        $this->artikel('Murni AI', []);

        $props = $this->actingAs(User::factory()->create())
            ->get('/admin/artikel?koreksi=1')
            ->viewData('page')['props'];

        $selesai = collect($props['daftarTahap'])->firstWhere('nilai', 'selesai');

        $this->assertSame(1, $selesai['jumlah']);
        $this->assertTrue($props['koreksi']);
    }

    /** @param  array<string, mixed>  $koreksi */
    private function artikel(string $judul, array $koreksi): Artikel
    {
        $artikel = Artikel::create([
            'media_id' => $this->media->id,
            'judul' => $judul,
            'url' => 'https://contoh.id/'.str($judul)->slug(),
            'url_kanonik' => 'https://contoh.id/'.str($judul)->slug(),
            'isi' => 'Isi berita contoh.',
            'jumlah_kata' => 3,
            'diambil_at' => now(),
            'status_proses' => 'selesai',
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'relevan' => true,
            'label_model' => 'netral',
            ...$koreksi,
        ]);

        return $artikel;
    }
}
