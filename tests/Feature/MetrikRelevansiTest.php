<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\GoldSet;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Models\User;
use App\Services\Nlp\EvaluatorModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Penyaring relevansi menentukan artikel mana yang masuk grafik, jadi angkanya
 * sama menentukannya dengan F1 sentimen. Sempat tidak diukur sama sekali —
 * dibangun di sprint 3 tanpa satu pun metrik.
 */
class MetrikRelevansiTest extends TestCase
{
    use RefreshDatabase;

    private KonteksPantauan $konteks;

    private User $pelabel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pelabel = User::factory()->create();
        $this->konteks = KonteksPantauan::create(['nama' => 'Pemkot', 'slug' => 'pemkot', 'utama' => true]);
    }

    private function pasangan(bool $goldRelevan, bool $modelRelevan): void
    {
        static $n = 0;
        $n++;

        $media = Media::firstOrCreate(['slug' => 'kp'], ['nama' => 'KP', 'domain' => 'kp.test']);

        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id,
            'judul' => "Berita {$n}",
            'url' => "https://kp.test/{$n}",
            'url_kanonik' => "https://kp.test/{$n}",
            'isi' => 'Isi.',
            'diambil_at' => now(),
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'konteks_pantauan_id' => $this->konteks->id,
            'relevan' => $modelRelevan,
            'label_model' => LabelSentimen::Netral,
            'keyakinan' => 0.99,
        ]);

        GoldSet::create([
            'artikel_id' => $artikel->id,
            'konteks_pantauan_id' => $this->konteks->id,
            'relevan_gold' => $goldRelevan,
            'label_gold' => LabelSentimen::Netral,
            'dilabeli_oleh' => $this->pelabel->id,
            'dilabeli_at' => now(),
            'ronde' => 1,
        ]);
    }

    public function test_presisi_dan_recall_dihitung_dari_kesepakatan_pelabel(): void
    {
        // 3 benar relevan, 1 salah dianggap relevan, 1 relevan terlewat, 2 benar tidak relevan.
        $this->pasangan(true, true);
        $this->pasangan(true, true);
        $this->pasangan(true, true);
        $this->pasangan(false, true);
        $this->pasangan(true, false);
        $this->pasangan(false, false);
        $this->pasangan(false, false);

        $metrik = app(EvaluatorModel::class)->metrikRelevansi();

        $this->assertSame(7, $metrik['jumlah_sampel']);
        $this->assertSame(3, $metrik['benar_relevan']);
        $this->assertSame(1, $metrik['salah_dianggap_relevan']);
        $this->assertSame(1, $metrik['relevan_yang_terlewat']);
        $this->assertSame(2, $metrik['benar_tidak_relevan']);

        $this->assertSame(0.75, $metrik['presisi']);   // 3 dari 4 yang disebut relevan
        $this->assertSame(0.75, $metrik['recall']);    // 3 dari 4 yang benar relevan
        $this->assertSame(0.75, $metrik['f1']);
    }

    /**
     * Kasus yang benar-benar terjadi: model menyebut relevan dua kali lebih
     * banyak daripada penilaian manusia. Metriknya harus menunjukkan itu, bukan
     * menyembunyikannya di balik akurasi yang terlihat wajar.
     */
    public function test_model_yang_terlalu_royal_terlihat_dari_presisi_rendah(): void
    {
        foreach (range(1, 3) as $i) {
            $this->pasangan(true, true);
        }

        foreach (range(1, 6) as $i) {
            $this->pasangan(false, true);
        }

        $metrik = app(EvaluatorModel::class)->metrikRelevansi();

        $this->assertSame(1.0, $metrik['recall'], 'Tidak ada yang terlewat.');
        $this->assertEqualsWithDelta(0.333, $metrik['presisi'], 0.001);
        // Akurasi saja akan terlihat 33% — presisi yang menjelaskan sebabnya.
        $this->assertLessThan(0.7, $metrik['presisi']);
    }

    public function test_gold_set_kosong_menghasilkan_null_bukan_pembagian_nol(): void
    {
        $this->assertNull(app(EvaluatorModel::class)->metrikRelevansi());
    }

    public function test_halaman_evaluasi_menampilkan_metrik_relevansi(): void
    {
        $this->pasangan(true, true);
        $this->pasangan(false, true);

        $this->actingAs($this->pelabel)
            ->get('/admin/evaluasi')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('relevansi.presisi', 0.5));
    }
}
