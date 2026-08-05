<?php

namespace Tests\Feature;

use App\Models\Artikel;
use App\Models\Media;
use App\Services\Crawler\PengunduhHalaman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * Backfill menempuh jalur berbeda dari crawl harian, isinya sudah di tangan,
 * jadi AmbilIsiArtikel dilewati. Yang tidak boleh berbeda: deduplikasi dan
 * penerusan ke analisis. Kalau keduanya menyimpang sedikit saja, satu peristiwa
 * terhitung dua kali dan seluruh angka dashboard ikut salah.
 */
class CrawlBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        Media::create(['nama' => 'Kendari Pos', 'slug' => 'kendari-pos', 'domain' => 'kp.test']);
    }

    /** @param list<array{0: string, 1: string}> $pos judul, isi */
    private function halamanArsip(array $pos): string
    {
        $json = [];

        foreach ($pos as $i => [$judul, $isi]) {
            $json[] = [
                'link' => "https://kp.test/berita-{$i}",
                'date_gmt' => '2026-07-01T09:00:00',
                'title' => ['rendered' => $judul],
                'content' => ['rendered' => "<p>{$isi}</p>"],
                'excerpt' => ['rendered' => '<p>Ringkasan.</p>'],
            ];
        }

        return json_encode($json);
    }

    private function pengunduh(string ...$tanggapan): void
    {
        $mock = Mockery::mock(PengunduhHalaman::class);
        $ke = 0;

        $mock->shouldReceive('unduh')->andReturnUsing(function () use ($tanggapan, &$ke) {
            // Habis halaman: kembalikan array kosong, tanda arsip sudah tuntas.
            return $tanggapan[$ke++] ?? '[]';
        });

        $this->app->instance(PengunduhHalaman::class, $mock);
    }

    public function test_menyimpan_artikel_beserta_isinya_tanpa_mengunduh_halaman_lagi(): void
    {
        $this->pengunduh($this->halamanArsip([
            ['Pemkot Kendari Perbaiki Drainase', str_repeat('kalimat berita drainase. ', 40)],
            ['Pasar Baru Diresmikan', str_repeat('kalimat berita pasar. ', 40)],
        ]));

        $this->artisan('crawl:backfill --halaman=1')->assertSuccessful();

        $this->assertSame(2, Artikel::withoutGlobalScopes()->count());

        $artikel = Artikel::withoutGlobalScopes()->where('judul', 'Pasar Baru Diresmikan')->first();

        $this->assertNotNull($artikel->isi, 'Isi harus ikut tersimpan dari respons arsip.');
        $this->assertNotNull($artikel->hash_isi);
        $this->assertNotNull($artikel->simhash);
        $this->assertSame('isi_diambil', $artikel->status_proses);
    }

    /**
     * Artikel asli berhenti menunggu diklasifikasi, bukan menghilang.
     *
     * Rantai job memang sengaja putus setelah dedup: klasifikasi Gemini
     * dijalankan lewat tombol, bukan di latar belakang. Yang diuji di sini
     * adalah bahwa artikelnya tetap berstatus `isi_diambil`, yaitu tahap yang
     * dibaca halaman Antrean Klasifikasi. Status lain berarti artikel itu
     * tidak akan pernah muncul untuk dinilai siapa pun.
     */
    public function test_artikel_asli_menunggu_diklasifikasi(): void
    {
        $this->pengunduh($this->halamanArsip([
            ['Judul A', str_repeat('kalimat pertama berbeda. ', 40)],
        ]));

        $this->artisan('crawl:backfill --halaman=1')->assertSuccessful();

        $artikel = Artikel::withoutGlobalScopes()->where('judul', 'Judul A')->firstOrFail();

        $this->assertSame('asli', $artikel->status_dedup->value);
        $this->assertSame('isi_diambil', $artikel->status_proses);
        Bus::assertNothingDispatched();
    }

    /** Deduplikasi harus berlaku sama seperti jalur crawl biasa. */
    public function test_isi_kembar_di_arsip_ditandai_salinan_bukan_asli(): void
    {
        $isi = str_repeat('kalimat berita yang sama persis. ', 40);

        $this->pengunduh($this->halamanArsip([
            ['Judul Pertama', $isi],
            ['Judul Kedua', $isi],
        ]));

        $this->artisan('crawl:backfill --halaman=1');

        $this->assertSame(1, Artikel::withoutGlobalScopes()->where('status_dedup', 'asli')->count());
        $this->assertSame(1, Artikel::withoutGlobalScopes()->where('status_dedup', 'salinan')->count());
    }

    /** URL yang sudah masuk lewat RSS tidak boleh tersimpan dua kali. */
    public function test_url_yang_sudah_ada_dilewati(): void
    {
        Artikel::withoutGlobalScopes()->create([
            'media_id' => Media::first()->id,
            'judul' => 'Sudah ada',
            'url' => 'https://kp.test/berita-0',
            'url_kanonik' => 'https://kp.test/berita-0',
            'diambil_at' => now(),
        ]);

        $this->pengunduh($this->halamanArsip([['Judul Baru', str_repeat('kalimat. ', 40)]]));

        $this->artisan('crawl:backfill --halaman=1');

        $this->assertSame(1, Artikel::withoutGlobalScopes()->count());
        $this->assertSame('Sudah ada', Artikel::withoutGlobalScopes()->value('judul'));
    }

    public function test_arsip_kosong_menghentikan_penarikan_tanpa_galat(): void
    {
        $this->pengunduh('[]');

        $this->artisan('crawl:backfill --halaman=5')->assertSuccessful();

        $this->assertSame(0, Artikel::withoutGlobalScopes()->count());
    }

    public function test_bisa_dibatasi_ke_satu_media(): void
    {
        Media::create(['nama' => 'Lain', 'slug' => 'lain', 'domain' => 'lain.test']);

        $this->pengunduh($this->halamanArsip([['Judul', str_repeat('kalimat. ', 40)]]));

        $this->artisan('crawl:backfill --media=kendari-pos --halaman=1')->assertSuccessful();

        $this->assertSame(
            Media::where('slug', 'kendari-pos')->value('id'),
            Artikel::withoutGlobalScopes()->value('media_id'),
        );
    }
}
