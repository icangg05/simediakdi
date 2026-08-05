<?php

namespace Tests\Feature;

use App\Jobs\AnalisisRelevansi;
use App\Jobs\AnalisisSentimen;
use App\Models\AnalisisSentimen as BarisAnalisis;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Services\Nlp\KlienNlp;
use App\Services\Relevance\RelevanceQualityGateService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MembuatModelRelevansi;
use Tests\TestCase;

/**
 * Penjaga yang mencegah sentimen berjalan di atas relevansi yang belum terbukti.
 *
 * Ini alur kelima dari daftar test wajib dokumen 02 bagian 9, dan sifatnya yang
 * membuatnya wajib: kegagalannya tidak terlihat. Sentimen yang berjalan padahal
 * seharusnya diblokir tetap menghasilkan angka yang tampak wajar di dashboard,
 * dan tidak ada satu pun galat yang muncul.
 */
class GerbangMutuRelevansiTest extends TestCase
{
    use MembuatModelRelevansi;
    use RefreshDatabase;

    private Artikel $artikel;

    protected function setUp(): void
    {
        parent::setUp();

        $media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        $this->artikel = Artikel::create([
            'media_id' => $media->id,
            'judul' => 'Pemkot Kendari memperbaiki drainase di Kadia',
            'url' => 'https://kp.test/drainase',
            'url_kanonik' => 'https://kp.test/drainase',
            'isi' => 'Pemerintah Kota Kendari memperbaiki drainase.',
            'diambil_at' => now(),
            'status_proses' => 'dianalisis',
        ]);
    }

    public function test_tanpa_model_produksi_gerbang_diblokir(): void
    {
        $gerbang = app(RelevanceQualityGateService::class);

        $this->assertSame('blocked', $gerbang->status()->value);
        $this->assertFalse($gerbang->lolos());
        $this->assertNotNull($gerbang->alasan());
    }

    public function test_analisis_relevansi_menahan_artikel_saat_gerbang_belum_lulus(): void
    {
        app()->call([new AnalisisRelevansi($this->artikel->id), 'handle']);

        $this->assertSame('model_belum_lulus_gate', $this->artikel->fresh()->status_proses);
    }

    /**
     * Penjaga kedua. Job sentimen menolak bekerja meski sudah terlanjur
     * didispatch, dan yang paling penting: layanan NLP tidak pernah dipanggil.
     */
    public function test_job_sentimen_berhenti_sendiri_saat_gerbang_belum_lulus(): void
    {
        $konteks = KonteksPantauan::create([
            'nama' => 'Pemerintah Kota Kendari',
            'slug' => 'pemkot-kendari',
            'utama' => true,
        ]);

        BarisAnalisis::create([
            'artikel_id' => $this->artikel->id,
            'konteks_pantauan_id' => $konteks->id,
            'relevan' => true,
        ]);

        $nlp = $this->mock(KlienNlp::class);
        $nlp->shouldNotReceive('sentimen');

        (new AnalisisSentimen($this->artikel->id))->handle($nlp, app(RelevanceQualityGateService::class));

        $this->assertNull(BarisAnalisis::first()->label_model);
        $this->assertSame('dianalisis', $this->artikel->fresh()->status_proses);
    }

    public function test_gerbang_lulus_hanya_saat_model_produksi_berstatus_passed(): void
    {
        $model = $this->modelRelevansiProduksi('passed');

        $this->assertTrue(app(RelevanceQualityGateService::class)->lolos());

        foreach (['blocked', 'needs_review', 'revoked'] as $status) {
            $model->update(['quality_gate_status' => $status]);

            $this->assertFalse(
                app(RelevanceQualityGateService::class)->lolos(),
                "Status {$status} seharusnya memblokir sentimen.",
            );
        }
    }

    /**
     * Promosi berjalan lewat beberapa langkah, dan satu langkah yang gagal di
     * tengah tanpa penjaga ini meninggalkan dua model produksi sekaligus. Yang
     * dipakai lalu bergantung urutan baris, artinya hasil analisis berubah
     * tanpa ada yang mengubah apa pun.
     */
    public function test_hanya_satu_model_boleh_berstatus_produksi(): void
    {
        $this->modelRelevansiProduksi('passed');

        $this->expectException(QueryException::class);

        $this->modelRelevansiProduksi('passed', 'simedia-relevancy-v2');
    }
}
