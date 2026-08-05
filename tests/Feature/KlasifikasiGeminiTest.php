<?php

namespace Tests\Feature;

use App\Ai\Agents\RelevanceClassifier;
use App\Ai\Agents\SentimentClassifier;
use App\Models\AnalisisSentimen as BarisAnalisis;
use App\Models\Artikel;
use App\Models\Media;
use App\Models\User;
use App\Services\Ai\KlasifikasiArtikel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Exceptions\RateLimitedException;
use Tests\TestCase;

/**
 * Alur klasifikasi Gemini, dari tombol sampai label akhir.
 *
 * Yang diuji di sini hal-hal yang kegagalannya tidak menimbulkan galat apa pun:
 * sentimen yang jalan padahal artikelnya tidak relevan, koreksi manusia yang
 * tertimpa klasifikasi ulang, bukti karangan yang lolos menjadi label, dan
 * artikel yang berubah status padahal Gemini gagal dipanggil.
 *
 * Semuanya menghasilkan dashboard yang tetap terisi angka yang tampak wajar.
 */
class KlasifikasiGeminiTest extends TestCase
{
    use RefreshDatabase;

    private Artikel $artikel;

    /** Kalimat yang benar-benar ada di isi artikel, jadi buktinya sah. */
    private const KUTIPAN_SAH = 'Pemerintah Kota Kendari memperbaiki drainase di Kecamatan Kadia';

    protected function setUp(): void
    {
        parent::setUp();

        $media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        $this->artikel = Artikel::create([
            'media_id' => $media->id,
            'judul' => 'Pemkot Kendari memperbaiki drainase di Kadia',
            'url' => 'https://kp.test/drainase',
            'url_kanonik' => 'https://kp.test/drainase',
            'ringkasan' => 'Perbaikan drainase dimulai pekan ini.',
            'isi' => self::KUTIPAN_SAH.'. Pekerjaan dimulai pekan ini dan '
                .'ditargetkan selesai dalam dua bulan menurut Dinas Pekerjaan Umum.',
            'dipublikasikan_at' => now(),
            'diambil_at' => now(),
            'status_proses' => 'isi_diambil',
        ]);
    }

    public function test_artikel_relevan_langsung_dinilai_sentimennya(): void
    {
        RelevanceClassifier::fake([$this->jawaban('relevan')]);
        SentimentClassifier::fake([$this->jawaban('positif')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $baris = BarisAnalisis::firstOrFail();

        $this->assertTrue($baris->relevan);
        $this->assertSame('positif', $baris->label_model->value);
        $this->assertSame('gemini', $baris->provider);
        $this->assertSame([self::KUTIPAN_SAH], $baris->evidence);
        $this->assertSame('selesai', $this->artikel->fresh()->status_proses);
    }

    /**
     * Sentimen menyusul relevansi, bukan berjalan sendiri.
     *
     * Model sentimen tetap mengeluarkan label untuk artikel yang tidak relevan,
     * dan label itu masuk agregasi tanpa ada yang menandainya salah.
     */
    public function test_artikel_tidak_relevan_tidak_dinilai_sentimennya(): void
    {
        RelevanceClassifier::fake([$this->jawaban('tidak_relevan')]);
        SentimentClassifier::fake([$this->jawaban('negatif')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $baris = BarisAnalisis::firstOrFail();

        $this->assertFalse($baris->relevan);
        $this->assertNull($baris->label_model);
        $this->assertSame('tidak_relevan', $this->artikel->fresh()->status_proses);
    }

    public function test_hasil_perlu_review_masuk_antrean_manusia(): void
    {
        RelevanceClassifier::fake([$this->jawaban('perlu_review')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $this->assertSame('perlu_review', $this->artikel->fresh()->status_proses);
        $this->assertFalse(BarisAnalisis::firstOrFail()->relevan);
    }

    /**
     * `perlu_review` bukan label sentimen.
     *
     * Memaksanya menjadi netral membuat dashboard menghitung keraguan model
     * sebagai pernyataan bahwa nadanya datar.
     */
    public function test_sentimen_ragu_mengosongkan_label_bukan_menjadi_netral(): void
    {
        RelevanceClassifier::fake([$this->jawaban('relevan')]);
        SentimentClassifier::fake([$this->jawaban('perlu_review')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $baris = BarisAnalisis::firstOrFail();

        $this->assertNull($baris->label_model);
        $this->assertTrue($baris->perlu_review);
    }

    /**
     * Bukti karangan adalah satu-satunya cacat Gemini yang tidak punya gejala
     * lain. Alasannya tetap kalimat Indonesia yang rapi dan tetap lolos skema.
     */
    public function test_bukti_yang_tidak_ada_di_artikel_menjadi_perlu_review(): void
    {
        RelevanceClassifier::fake([[
            'label' => 'relevan',
            'reason_code' => 'program_pemkot',
            'reason_summary' => 'Artikel membahas program Pemkot.',
            'evidence' => ['Wali Kota meresmikan jembatan baru di Poasia pekan lalu'],
            'requires_manual_review' => false,
        ]]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        // Bukan tidak_relevan. Yang gagal alasannya, bukan artikelnya.
        $this->assertSame('perlu_review', $this->artikel->fresh()->status_proses);
        $this->assertFalse(BarisAnalisis::firstOrFail()->relevan);
    }

    /**
     * F-13: koreksi manusia mengalahkan hasil model.
     *
     * Sebelum penanda `relevan_manual` ada, keputusan antrean review ditulis ke
     * kolom `relevan` yang sama persis dengan yang ditimpa klasifikasi ulang,
     * tanpa cara apa pun untuk mengetahui bahwa isinya keputusan manusia.
     */
    public function test_relevansi_manual_bertahan_setelah_klasifikasi_ulang(): void
    {
        $baris = BarisAnalisis::create([
            'artikel_id' => $this->artikel->id,
            'relevan' => true,
            'relevan_manual' => true,
            'relevan_dikoreksi_at' => now(),
        ]);

        RelevanceClassifier::fake([$this->jawaban('tidak_relevan')]);

        app(KlasifikasiArtikel::class)->jalankan($this->artikel);

        $this->assertTrue($baris->fresh()->relevan);
        $this->assertSame('selesai', $this->artikel->fresh()->status_proses);
    }

    public function test_label_sentimen_manual_mengalahkan_hasil_gemini(): void
    {
        $baris = BarisAnalisis::create([
            'artikel_id' => $this->artikel->id,
            'relevan' => true,
            'label_manual' => 'positif',
        ]);

        SentimentClassifier::fake([$this->jawaban('negatif')]);

        app(KlasifikasiArtikel::class)->jalankanSentimen($this->artikel);

        $baris->refresh();

        $this->assertSame('negatif', $baris->label_model->value);
        $this->assertSame('positif', $baris->label_manual->value);
        $this->assertSame('positif', $baris->label_efektif->value);
        $this->assertFalse($baris->perlu_review);
    }

    /**
     * Kegagalan Gemini tidak boleh mengubah status artikel.
     *
     * Yang dilarang adalah halaman yang menelan galatnya lalu menandai artikel
     * `tidak_relevan`, karena artikel itu tidak akan pernah dinilai ulang oleh
     * siapa pun.
     */
    public function test_artikel_tidak_hilang_saat_gemini_gagal(): void
    {
        RelevanceClassifier::fake(fn () => throw RateLimitedException::forProvider('gemini'));

        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->post("/admin/review/{$this->artikel->id}/klasifikasi")
            ->assertRedirect()
            ->assertSessionHas('galat');

        $this->assertSame('isi_diambil', $this->artikel->fresh()->status_proses);
        $this->assertSame(0, BarisAnalisis::count());
    }

    public function test_halaman_antrean_menyaring_menurut_tahap(): void
    {
        $this->actingAs(User::factory()->create(['peran' => 'superadmin']))
            ->get('/admin/review?status=isi_diambil')
            ->assertOk()
            ->assertInertia(fn ($halaman) => $halaman
                ->component('admin/Review')
                ->where('status', 'isi_diambil')
                ->has('artikel.data', 1)
                ->has('saringan', 5));
    }

    /** @return array<string, mixed> */
    private function jawaban(string $label): array
    {
        return [
            'label' => $label,
            'reason_code' => 'program_pemkot',
            'reason_summary' => 'Artikel membahas pekerjaan drainase Pemkot Kendari.',
            'evidence' => [self::KUTIPAN_SAH],
            'requires_manual_review' => false,
        ];
    }
}
