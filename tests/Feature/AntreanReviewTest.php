<?php

namespace Tests\Feature;

use App\Jobs\AnalisisSentimen;
use App\Models\AnalisisSentimen as BarisAnalisis;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Antrean artikel yang skornya di antara dua ambang.
 *
 * Yang dijaga di sini: keputusan admin benar-benar mengeluarkan artikel dari
 * antrean dan dari dashboard, dan artikel yang dinyatakan relevan diteruskan
 * ke penilaian sentimen, bukan berhenti diam-diam.
 */
class AntreanReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private KonteksPantauan $konteks;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->admin = User::factory()->create(['peran' => 'superadmin']);
        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        $this->konteks = KonteksPantauan::create([
            'nama' => 'Pemerintah Kota Kendari',
            'slug' => 'pemerintah-kota-kendari',
            'kata_kunci' => ['pemkot kendari'],
            'utama' => true,
            'aktif' => true,
        ]);

        config(['nlp.ambang.relevansi_atas' => 0.84, 'nlp.ambang.relevansi_bawah' => 0.83]);
    }

    private function artikelRagu(float $skor = 0.835): BarisAnalisis
    {
        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id,
            'judul' => 'Pemkot Kendari hadir di acara',
            'url' => 'https://kp.test/'.uniqid(),
            'url_kanonik' => 'https://kp.test/'.uniqid(),
            'isi' => 'Acara berlangsung meriah. Pemkot Kendari hadir sebagai tamu.',
            'diambil_at' => now(),
            'status_proses' => 'perlu_review',
        ]);

        return BarisAnalisis::create([
            'artikel_id' => $artikel->id,
            'konteks_pantauan_id' => $this->konteks->id,
            'relevan' => false,
            'skor_relevansi' => $skor,
        ]);
    }

    public function test_antrean_menampilkan_artikel_ragu_beserta_skornya(): void
    {
        $analisis = $this->artikelRagu();

        $this->actingAs($this->admin)->get('/admin/review')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/Review')
                ->where('sisa', 1)
                ->where('artikel.analisis_id', $analisis->id)
                ->where('artikel.skor_relevansi', 0.835)
                // Jumlah sebutan ditampilkan karena itu yang paling sering
                // menentukan jawabannya tanpa perlu membaca seluruh artikel.
                ->where('artikel.sebutan.judul', 1),
            );
    }

    public function test_ditandai_relevan_diteruskan_ke_sentimen(): void
    {
        $analisis = $this->artikelRagu();

        $this->actingAs($this->admin)
            ->post('/admin/review', ['analisis_id' => $analisis->id, 'relevan' => true])
            ->assertRedirect();

        $this->assertTrue($analisis->fresh()->relevan);
        $this->assertSame('dianalisis', $analisis->artikel->fresh()->status_proses);
        Queue::assertPushed(AnalisisSentimen::class);
    }

    public function test_ditandai_tidak_relevan_keluar_dari_antrean_dan_dashboard(): void
    {
        $analisis = $this->artikelRagu();

        $this->actingAs($this->admin)
            ->post('/admin/review', ['analisis_id' => $analisis->id, 'relevan' => false])
            ->assertRedirect();

        $this->assertFalse($analisis->fresh()->relevan);
        $this->assertSame('tidak_relevan', $analisis->artikel->fresh()->status_proses);
        Queue::assertNotPushed(AnalisisSentimen::class);

        // Antreannya ikut kosong, bukan hanya statusnya berubah.
        $this->actingAs($this->admin)->get('/admin/review')
            ->assertInertia(fn ($page) => $page->where('sisa', 0)->where('artikel', null));
    }

    public function test_artikel_paling_dekat_ambang_atas_disodorkan_lebih_dulu(): void
    {
        $jauh = $this->artikelRagu(0.831);
        $dekat = $this->artikelRagu(0.8395);

        // Di sinilah model paling sering salah, dan artikel yang nyaris lolos
        // paling merugikan kalau dibiarkan salah masuk.
        $this->actingAs($this->admin)->get('/admin/review')
            ->assertInertia(fn ($page) => $page->where('artikel.analisis_id', $dekat->id));

        $this->assertNotSame($jauh->id, $dekat->id);
    }

    public function test_peran_media_tidak_bisa_membuka_antrean(): void
    {
        $media = User::factory()->create(['peran' => 'media', 'media_id' => $this->media->id]);

        $this->actingAs($media)->get('/admin/review')->assertForbidden();
    }
}
