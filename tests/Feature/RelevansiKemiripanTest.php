<?php

namespace Tests\Feature;

use App\Jobs\AnalisisRelevansi;
use App\Jobs\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Services\Nlp\PenyaringKataKunci;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Pgvector\Laravel\Vector;
use Tests\TestCase;

/**
 * Tiga cabang relevansi dari satu angka kemiripan.
 *
 * Yang diuji bukan mutu modelnya melainkan keputusannya: artikel ragu tidak
 * boleh diam-diam masuk dashboard, artikel yang lolos ambang tapi hanya
 * menyebut Pemkot sekali tetap harus ditolak, dan selama ambang belum diukur
 * tidak boleh ada satu pun yang otomatis dinyatakan relevan.
 */
class RelevansiKemiripanTest extends TestCase
{
    use RefreshDatabase;

    private KonteksPantauan $konteks;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        $this->konteks = KonteksPantauan::create([
            'nama' => 'Pemerintah Kota Kendari',
            'slug' => 'pemerintah-kota-kendari',
            'deskripsi_model' => 'Artikel membahas Pemerintah Kota Kendari.',
            'kata_kunci' => ['pemkot kendari'],
            'utama' => true,
            'aktif' => true,
            // Vektor konteks dibuat sesederhana mungkin: sumbu pertama.
            // Kemiripan artikel terhadapnya jadi bisa disetel persis lewat
            // sudut vektor artikelnya, tanpa memanggil model apa pun.
            'embedding' => new Vector($this->sumbu(1.0, 0.0)),
        ]);

        config(['nlp.ambang.relevansi_atas' => 0.80, 'nlp.ambang.relevansi_bawah' => 0.50]);
    }

    /** Vektor 384 dimensi dengan dua sumbu pertama diisi, sisanya nol. */
    private function sumbu(float $x, float $y): array
    {
        return [$x, $y, ...array_fill(0, 382, 0.0)];
    }

    /**
     * Kemiripan yang diminta ditetapkan lewat sudut: cosine antara (1,0) dan
     * (cos t, sin t) sama dengan cos t, jadi vektor artikel dibentuk langsung
     * dari skor yang diinginkan.
     */
    private function artikel(float $kemiripan, string $isi): Artikel
    {
        $y = sqrt(max(0.0, 1 - $kemiripan ** 2));

        return Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media->id,
            'judul' => 'Berita uji',
            'url' => 'https://kp.test/'.uniqid(),
            'url_kanonik' => 'https://kp.test/'.uniqid(),
            'isi' => $isi,
            'diambil_at' => now(),
            'status_proses' => 'isi_diambil',
            'embedding_relevansi' => new Vector($this->sumbu($kemiripan, $y)),
        ]);
    }

    private function jalankan(Artikel $artikel): void
    {
        app(AnalisisRelevansi::class, ['artikelId' => $artikel->id])
            ->handle(app(PenyaringKataKunci::class));
    }

    /** Isi yang menyebut konteks cukup sering untuk lolos pengetat sebutan. */
    private function isiMenonjol(): string
    {
        return str_repeat('Pemkot Kendari membangun drainase baru. ', 4);
    }

    public function test_kemiripan_tinggi_dan_sebutan_menonjol_dinyatakan_relevan(): void
    {
        $artikel = $this->artikel(0.95, $this->isiMenonjol());

        $this->jalankan($artikel);

        $this->assertTrue($artikel->fresh()->analisisSentimen->first()->relevan);
        $this->assertSame('dianalisis', $artikel->fresh()->status_proses);
        Queue::assertPushed(AnalisisSentimen::class);
    }

    public function test_kemiripan_di_antara_dua_ambang_masuk_perlu_review(): void
    {
        $artikel = $this->artikel(0.65, $this->isiMenonjol());

        $this->jalankan($artikel);

        $this->assertSame('perlu_review', $artikel->fresh()->status_proses);
        $this->assertFalse($artikel->fresh()->analisisSentimen->first()->relevan);

        // Artikel ragu tidak boleh diam-diam ikut dinilai sentimennya.
        Queue::assertNotPushed(AnalisisSentimen::class);
    }

    public function test_kemiripan_rendah_dinyatakan_tidak_relevan(): void
    {
        $artikel = $this->artikel(0.20, $this->isiMenonjol());

        $this->jalankan($artikel);

        $this->assertSame('tidak_relevan', $artikel->fresh()->status_proses);
        Queue::assertNotPushed(AnalisisSentimen::class);
    }

    public function test_lolos_ambang_tapi_hanya_disebut_sekali_tetap_ditolak(): void
    {
        // Inilah kesalahan yang membuat presisi model lama hanya 54%: teks yang
        // dinilai disusun dari sekitar sebutan Pemkot, jadi penyebutan sekali
        // lewat pun bisa berskor tinggi.
        $artikel = $this->artikel(0.95, 'Festival musik digelar di lapangan. Pemkot Kendari hadir sebagai tamu. Acara berlangsung meriah.');

        $this->jalankan($artikel);

        $this->assertFalse($artikel->fresh()->analisisSentimen->first()->relevan);
        $this->assertSame('tidak_relevan', $artikel->fresh()->status_proses);
    }

    public function test_ambang_belum_diukur_membuat_semuanya_perlu_review(): void
    {
        config(['nlp.ambang.relevansi_atas' => null, 'nlp.ambang.relevansi_bawah' => null]);

        $artikel = $this->artikel(0.99, $this->isiMenonjol());

        $this->jalankan($artikel);

        // Tanpa ambang terukur, tidak ada yang boleh otomatis masuk dashboard
        // dan tidak ada yang boleh otomatis dibuang.
        $this->assertSame('perlu_review', $artikel->fresh()->status_proses);
        Queue::assertNotPushed(AnalisisSentimen::class);
    }

    public function test_skor_kemiripan_tersimpan_untuk_penyetelan_ambang(): void
    {
        $artikel = $this->artikel(0.95, $this->isiMenonjol());

        $this->jalankan($artikel);

        // Skornya harus tersimpan apa pun keputusannya. Tanpa itu, menyetel
        // ambang menuntut inferensi ulang, dan ambang yang mahal dicoba tidak
        // pernah benar-benar disetel.
        $this->assertEqualsWithDelta(0.95, $artikel->fresh()->analisisSentimen->first()->skor_relevansi, 0.01);
    }
}
