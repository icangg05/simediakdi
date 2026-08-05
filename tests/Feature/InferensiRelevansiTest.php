<?php

namespace Tests\Feature;

use App\Jobs\AnalisisRelevansi;
use App\Jobs\AnalisisSentimen;
use App\Models\AnalisisSentimen as BarisAnalisis;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Models\PrediksiRelevansi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MembuatModelRelevansi;
use Tests\TestCase;

/**
 * Jalur produksi relevansi: classifier menilai, ambang memutuskan, sentimen menyusul.
 *
 * Sebelum ini `AnalisisRelevansi` sengaja melempar begitu gerbang lolos, karena
 * inferensinya belum ada. Yang diuji di sini bukan sekadar bahwa ia tidak lagi
 * melempar, melainkan bahwa keputusannya memakai ambang milik model produksi.
 * Ambang yang salah tidak memunculkan galat apa pun, ia hanya menyaring artikel
 * yang keliru, dan itu baru ketahuan setelah dashboard dibaca pimpinan.
 */
class InferensiRelevansiTest extends TestCase
{
    use MembuatModelRelevansi;
    use RefreshDatabase;

    private Artikel $artikel;

    private KonteksPantauan $konteks;

    protected function setUp(): void
    {
        parent::setUp();

        $media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        $this->artikel = Artikel::create([
            'media_id' => $media->id,
            'judul' => 'Pemkot Kendari memperbaiki drainase di Kadia',
            'url' => 'https://kp.test/drainase',
            'url_kanonik' => 'https://kp.test/drainase',
            'isi' => 'Pemerintah Kota Kendari memperbaiki drainase di Kecamatan Kadia.',
            'diambil_at' => now(),
            'status_proses' => 'isi_diambil',
        ]);

        $this->konteks = KonteksPantauan::create([
            'nama' => 'Pemerintah Kota Kendari',
            'slug' => 'pemkot-kendari',
            'deskripsi_model' => 'Pemerintah Kota Kendari',
            'utama' => true,
            'aktif' => true,
        ]);
    }

    public function test_artikel_di_atas_ambang_diteruskan_ke_sentimen(): void
    {
        Queue::fake();
        $this->modelRelevansiProduksi();
        $this->jawabanModel(0.93);

        app()->call([new AnalisisRelevansi($this->artikel->id), 'handle']);

        $this->assertSame('dianalisis', $this->artikel->fresh()->status_proses);

        $baris = BarisAnalisis::where('artikel_id', $this->artikel->id)->firstOrFail();

        $this->assertTrue($baris->relevan);
        $this->assertEqualsWithDelta(0.93, $baris->skor_relevansi, 0.0001);

        Queue::assertPushed(AnalisisSentimen::class);
    }

    /**
     * Ambang yang menentukan, bukan 0,5 bawaan model.
     *
     * Ambang uji 0,6, jadi peluang 0,55 berarti tidak relevan meski classifier
     * sendiri akan menyebutnya relevan. Kalau baris ini gagal, artinya keputusan
     * produksi mengabaikan versi ambang dan seluruh penalaan ambang tidak
     * berpengaruh apa pun.
     */
    public function test_peluang_di_bawah_ambang_tidak_diteruskan(): void
    {
        Queue::fake();
        $this->modelRelevansiProduksi();
        $this->jawabanModel(0.55);

        app()->call([new AnalisisRelevansi($this->artikel->id), 'handle']);

        $this->assertFalse(BarisAnalisis::where('artikel_id', $this->artikel->id)->firstOrFail()->relevan);

        Queue::assertNotPushed(AnalisisSentimen::class);
    }

    /** Pita review 0,4 sampai 0,6 pada ambang uji. */
    public function test_peluang_di_pita_review_masuk_antrean_manusia(): void
    {
        Queue::fake();
        $this->modelRelevansiProduksi();
        $this->jawabanModel(0.45);

        app()->call([new AnalisisRelevansi($this->artikel->id), 'handle']);

        $this->assertSame('perlu_review', $this->artikel->fresh()->status_proses);
        $this->assertTrue(PrediksiRelevansi::where('artikel_id', $this->artikel->id)->firstOrFail()->review_required);
    }

    public function test_peluang_jauh_di_bawah_ambang_ditandai_tidak_relevan(): void
    {
        Queue::fake();
        $this->modelRelevansiProduksi();
        $this->jawabanModel(0.04);

        app()->call([new AnalisisRelevansi($this->artikel->id), 'handle']);

        $this->assertSame('tidak_relevan', $this->artikel->fresh()->status_proses);
    }

    /**
     * Prediksi disimpan meski artikelnya tidak relevan.
     *
     * Di situlah alasan artikel tidak muncul di dashboard bisa ditelusuri.
     * Tanpa barisnya, "dinilai tidak relevan" dan "belum pernah dinilai" terlihat
     * persis sama.
     */
    public function test_prediksi_tersimpan_lengkap_dengan_versi_yang_dipakai(): void
    {
        Queue::fake();
        $model = $this->modelRelevansiProduksi();
        $this->jawabanModel(0.12);

        app()->call([new AnalisisRelevansi($this->artikel->id), 'handle']);

        $prediksi = PrediksiRelevansi::where('artikel_id', $this->artikel->id)->firstOrFail();

        $this->assertSame($model->id, $prediksi->versi_model_relevansi_id);
        $this->assertSame($model->versi_threshold_relevansi_id, $prediksi->versi_threshold_relevansi_id);
        $this->assertSame('tidak_relevan', $prediksi->label_prediksi->value);
        $this->assertNotSame('', $prediksi->input_hash);
    }

    /** Layanan NLP tidak pernah dipanggil selama gerbang belum lolos. */
    public function test_gerbang_terblokir_menahan_artikel_tanpa_memanggil_layanan(): void
    {
        Http::fake();

        app()->call([new AnalisisRelevansi($this->artikel->id), 'handle']);

        $this->assertSame('model_belum_lulus_gate', $this->artikel->fresh()->status_proses);

        Http::assertNothingSent();
    }

    private function jawabanModel(float $peluang): void
    {
        Http::fake([
            '*/relevancy/predict' => Http::response([
                'hasil' => [[
                    'id' => $this->konteks->id,
                    'probabilitas_relevan' => $peluang,
                    'probabilitas_tidak_relevan' => round(1 - $peluang, 4),
                    'input_tokens' => 128,
                    'input_truncated' => false,
                    'inference_ms' => 210,
                ]],
            ]),
        ]);
    }
}
