<?php

namespace Tests\Feature;

use App\Jobs\AmbilIsiArtikel;
use App\Models\Artikel;
use App\Models\Media;
use App\Services\Crawler\EkstraktorWordPress;
use App\Services\Crawler\GagalMengunduh;
use App\Services\Crawler\HasilEkstraksi;
use App\Services\Crawler\PengunduhHalaman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * Urutan pengambilan isi: Readability dulu, WP REST hanya sebagai jaring
 * pengaman. Membaliknya berarti membayar tiga kali lipat latensi dan membebani
 * hosting kecil media daerah tanpa keuntungan yang terukur.
 */
class AmbilIsiArtikelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Yang diuji cara isi diambil, bukan seluruh pipeline. Rantai job
        // memang sudah berhenti setelah dedup sejak klasifikasi berpindah ke
        // tombol, tetapi fake ini tetap dipertahankan supaya test ini tidak
        // ikut gagal kalau nanti ada job baru yang disambungkan di sana.
        Bus::fake();
    }

    private function artikel(): Artikel
    {
        $media = Media::create(['nama' => 'Contoh', 'slug' => 'contoh', 'domain' => 'contoh.id']);

        return Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id,
            'judul' => 'Judul dari feed',
            'url' => 'https://contoh.id/judul-berita/',
            'url_kanonik' => 'https://contoh.id/judul-berita',
            'diambil_at' => now(),
        ]);
    }

    /** @param list<string> $kata */
    private function halaman(int $jumlahKata): string
    {
        return '<html><body><article><h1>Judul</h1><p>'
            .implode(' ', array_fill(0, $jumlahKata, 'kalimat'))
            .'</p></article></body></html>';
    }

    public function test_wp_api_tidak_disentuh_saat_readability_berhasil(): void
    {
        $artikel = $this->artikel();

        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->once()->andReturn($this->halaman(400));
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $wordpress = Mockery::mock(EkstraktorWordPress::class);
        // Inti tesnya: jalur API tidak boleh ditembak sama sekali.
        $wordpress->shouldNotReceive('ekstrak');
        $this->app->instance(EkstraktorWordPress::class, $wordpress);

        $this->app->call([new AmbilIsiArtikel($artikel->id), 'handle']);

        $artikel->refresh();
        $this->assertSame('isi_diambil', $artikel->status_proses);
        $this->assertGreaterThan(80, $artikel->jumlah_kata);
    }

    public function test_wp_api_dipakai_saat_halaman_tidak_bisa_diunduh(): void
    {
        $artikel = $this->artikel();

        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->once()->andThrow(new GagalMengunduh('HTTP 503.', 503));
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $wordpress = Mockery::mock(EkstraktorWordPress::class);
        $wordpress->shouldReceive('ekstrak')->once()->andReturn(new HasilEkstraksi(
            judul: 'Judul dari API',
            isi: implode(' ', array_fill(0, 300, 'kalimat')),
            ringkasan: 'Ringkasan',
            penulis: 'Redaksi',
            gambarUrl: 'https://contoh.id/gambar.jpg',
            dipublikasikanAt: null,
            jumlahKata: 300,
        ));
        $this->app->instance(EkstraktorWordPress::class, $wordpress);

        $this->app->call([new AmbilIsiArtikel($artikel->id), 'handle']);

        $artikel->refresh();
        $this->assertSame('isi_diambil', $artikel->status_proses);
        $this->assertSame('Redaksi', $artikel->penulis);
        $this->assertSame(300, $artikel->jumlah_kata);
    }

    public function test_wp_api_dipakai_saat_readability_hanya_dapat_teaser(): void
    {
        $artikel = $this->artikel();

        // Di bawah ambang 80 kata: halaman teaser, isinya belum lengkap.
        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->once()->andReturn($this->halaman(20));
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $wordpress = Mockery::mock(EkstraktorWordPress::class);
        $wordpress->shouldReceive('ekstrak')->once()->andReturn(new HasilEkstraksi(
            judul: 'Judul lengkap',
            isi: implode(' ', array_fill(0, 500, 'kalimat')),
            ringkasan: null,
            penulis: null,
            gambarUrl: null,
            dipublikasikanAt: null,
            jumlahKata: 500,
        ));
        $this->app->instance(EkstraktorWordPress::class, $wordpress);

        $this->app->call([new AmbilIsiArtikel($artikel->id), 'handle']);

        $this->assertSame(500, $artikel->refresh()->jumlah_kata);
    }

    public function test_hasil_readability_dipertahankan_kalau_api_justru_lebih_pendek(): void
    {
        $artikel = $this->artikel();

        $pengunduh = Mockery::mock(PengunduhHalaman::class);
        $pengunduh->shouldReceive('unduh')->once()->andReturn($this->halaman(40));
        $this->app->instance(PengunduhHalaman::class, $pengunduh);

        $wordpress = Mockery::mock(EkstraktorWordPress::class);
        $wordpress->shouldReceive('ekstrak')->once()->andReturn(new HasilEkstraksi(
            judul: 'Judul', isi: 'dua kata', ringkasan: null, penulis: null,
            gambarUrl: null, dipublikasikanAt: null, jumlahKata: 2,
        ));
        $this->app->instance(EkstraktorWordPress::class, $wordpress);

        $this->app->call([new AmbilIsiArtikel($artikel->id), 'handle']);

        // Keduanya di bawah ambang, tapi jangan tukar dengan yang lebih buruk.
        $this->assertGreaterThan(2, $artikel->refresh()->jumlah_kata);
    }
}
