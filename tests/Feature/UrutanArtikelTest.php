<?php

namespace Tests\Feature;

use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\Media;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Urutan daftar di halaman `/admin/artikel`.
 *
 * Tanggal terbit yang diutamakan, tanggal masuk hanya memecah seri. Crawler
 * menarik satu feed sekaligus, jadi banyak artikel bisa punya tanggal yang
 * sama persis sampai ke detiknya, dan di dalam rombongan itu urutannya harus
 * tetap pasti, bukan ditentukan kebetulan.
 */
class UrutanArtikelTest extends TestCase
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

    /** Tanggal terbit menang, sekalipun artikel lain jauh lebih baru masuknya. */
    public function test_tanggal_terbit_lebih_diutamakan_daripada_tanggal_masuk(): void
    {
        $this->artikel('Masuk kemarin, terbit hari ini', masuk: now()->subDay(), terbit: now());
        $this->artikel('Masuk hari ini, terbit bulan lalu', masuk: now(), terbit: now()->subMonth());

        $this->assertSame(
            ['Masuk kemarin, terbit hari ini', 'Masuk hari ini, terbit bulan lalu'],
            $this->judul(),
        );
    }

    /** Pada tanggal terbit yang sama, yang paling baru masuk naik ke atas. */
    public function test_tanggal_masuk_memecah_seri_pada_tanggal_terbit_yang_sama(): void
    {
        $terbit = now()->subDay();

        $this->artikel('Masuk lebih lama', masuk: now()->subHours(5), terbit: $terbit);
        $this->artikel('Masuk paling baru', masuk: now(), terbit: $terbit);

        $this->assertSame(['Masuk paling baru', 'Masuk lebih lama'], $this->judul());
    }

    /**
     * Artikel tanpa tanggal terbit jatuh ke paling bawah, bukan naik ke puncak.
     *
     * Ini yang gampang salah. Pada pengurutan menurun Postgres menaruh null
     * paling atas, jadi tanpa `NULLS LAST` satu feed yang tidak mencantumkan
     * tanggal terbit akan menguasai seluruh puncak daftar begitu ia masuk.
     */
    public function test_artikel_tanpa_tanggal_terbit_ditaruh_paling_bawah(): void
    {
        $this->artikel('Terbit lama', masuk: now()->subDays(3), terbit: now()->subDays(3));
        $this->artikel('Tanpa tanggal terbit', masuk: now(), terbit: null);
        $this->artikel('Terbit baru', masuk: now()->subHour(), terbit: now()->subHour());

        $this->assertSame(
            ['Terbit baru', 'Terbit lama', 'Tanpa tanggal terbit'],
            $this->judul(),
        );
    }

    /**
     * Dua artikel yang seluruh tanggalnya sama tetap punya urutan yang pasti.
     *
     * Tanpa pemecah seri terakhir, paginasi bisa mengulang baris di halaman
     * berikutnya dan melewatkan baris lain tanpa jejak, karena Postgres tidak
     * menjanjikan urutan apa pun untuk baris yang nilai sortirnya sama.
     */
    public function test_artikel_dengan_tanggal_identik_diurutkan_dari_yang_terbaru_disimpan(): void
    {
        $masuk = now();
        $terbit = now()->subHour();

        $lebihDulu = $this->artikel('Disimpan lebih dulu', masuk: $masuk, terbit: $terbit);
        $menyusul = $this->artikel('Disimpan menyusul', masuk: $masuk, terbit: $terbit);

        $this->assertGreaterThan($lebihDulu->id, $menyusul->id);
        $this->assertSame(['Disimpan menyusul', 'Disimpan lebih dulu'], $this->judul());
    }

    /** @return list<string> */
    private function judul(): array
    {
        return collect(
            $this->actingAs(User::factory()->create())
                ->get('/admin/artikel')
                ->viewData('page')['props']['artikel']['data'],
        )->pluck('judul')->all();
    }

    private function artikel(string $judul, CarbonInterface $masuk, ?CarbonInterface $terbit): Artikel
    {
        $artikel = Artikel::create([
            'media_id' => $this->media->id,
            'judul' => $judul,
            'url' => 'https://contoh.id/'.str($judul)->slug(),
            'url_kanonik' => 'https://contoh.id/'.str($judul)->slug(),
            'isi' => 'Isi berita contoh.',
            'jumlah_kata' => 3,
            'diambil_at' => $masuk,
            'dipublikasikan_at' => $terbit,
            'status_proses' => 'selesai',
        ]);

        // Baris analisis wajib ada. Halaman terbuka pada tahap Selesai dengan
        // saringan Relevan sebagai bawaan, jadi artikel tanpa baris analisis
        // tidak akan pernah muncul di daftar yang sedang diuji.
        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'relevan' => true,
            'label_model' => 'netral',
        ]);

        return $artikel;
    }
}
