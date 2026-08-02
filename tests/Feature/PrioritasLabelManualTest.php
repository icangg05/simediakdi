<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Jobs\AnalisisSentimen as JobAnalisisSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Models\User;
use App\Services\Nlp\DTO\HasilSentimen;
use App\Services\Nlp\KlienNlp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tes wajib dokumen 02 bagian 9 nomor 4.
 *
 * Koreksi manusia selalu mengalahkan hasil model (F-13). Kalau analisis ulang
 * bisa menimpanya, admin yang sudah membetulkan label akan menemukannya berubah
 * sendiri beberapa jam kemudian — dan berhenti mempercayai seluruh sistem.
 */
class PrioritasLabelManualTest extends TestCase
{
    use RefreshDatabase;

    private Artikel $artikel;

    private KonteksPantauan $konteks;

    protected function setUp(): void
    {
        parent::setUp();

        $media = Media::create(['nama' => 'Contoh', 'slug' => 'contoh', 'domain' => 'contoh.id']);

        $this->artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id,
            'judul' => 'Perbaikan drainase tuntas',
            'url' => 'https://contoh.id/berita',
            'url_kanonik' => 'https://contoh.id/berita',
            'isi' => 'Isi berita tentang drainase di Kendari.',
            'diambil_at' => now(),
        ]);

        $this->konteks = KonteksPantauan::create([
            'nama' => 'Pemerintah Kota Kendari',
            'slug' => 'pemkot-kendari',
            'utama' => true,
        ]);
    }

    private function analisis(array $tambahan = []): AnalisisSentimen
    {
        return AnalisisSentimen::create([
            'artikel_id' => $this->artikel->id,
            'konteks_pantauan_id' => $this->konteks->id,
            'relevan' => true,
            'label_model' => LabelSentimen::Negatif,
            'skor_negatif' => 0.8, 'skor_netral' => 0.15, 'skor_positif' => 0.05,
            'keyakinan' => 0.8,
            'model_versi' => 'uji-1.0',
            'dianalisis_at' => now(),
            ...$tambahan,
        ]);
    }

    private function jalankanAnalisisUlang(string $labelBaru): void
    {
        $nlp = Mockery::mock(KlienNlp::class);
        $nlp->shouldReceive('sentimen')->once()->andReturn([
            $this->konteks->id => new HasilSentimen(
                artikelId: $this->konteks->id,
                label: LabelSentimen::from($labelBaru),
                skorNegatif: 0.1, skorNetral: 0.1, skorPositif: 0.8,
                keyakinan: 0.8,
                modelVersi: 'uji-2.0',
            ),
        ]);
        $this->app->instance(KlienNlp::class, $nlp);

        $this->app->call([new JobAnalisisSentimen($this->artikel->id), 'handle']);
    }

    public function test_label_efektif_memakai_label_manual_saat_ada(): void
    {
        $analisis = $this->analisis(['label_manual' => LabelSentimen::Positif]);

        $this->assertSame(LabelSentimen::Positif, $analisis->refresh()->label_efektif);
    }

    public function test_label_efektif_jatuh_ke_label_model_saat_tidak_dikoreksi(): void
    {
        $analisis = $this->analisis();

        $this->assertSame(LabelSentimen::Negatif, $analisis->refresh()->label_efektif);
    }

    public function test_analisis_ulang_tidak_menimpa_koreksi_manual(): void
    {
        $analisis = $this->analisis(['label_manual' => LabelSentimen::Netral]);

        $this->jalankanAnalisisUlang('positif');

        $analisis->refresh();

        // Model boleh berubah pikiran; koreksi manusia tidak ikut berubah.
        $this->assertSame(LabelSentimen::Positif, $analisis->label_model);
        $this->assertSame(LabelSentimen::Netral, $analisis->label_manual);
        $this->assertSame(LabelSentimen::Netral, $analisis->label_efektif);
    }

    public function test_koreksi_lewat_halaman_menghapus_status_perlu_review(): void
    {
        $analisis = $this->analisis(['keyakinan' => 0.4, 'perlu_review' => true]);

        $this->actingAs(User::factory()->create())
            ->put("/admin/analisis/{$analisis->id}", [
                'label_manual' => 'positif',
                'catatan_koreksi' => 'Berita peresmian, nadanya jelas positif.',
            ])
            ->assertRedirect();

        $analisis->refresh();

        $this->assertSame(LabelSentimen::Positif, $analisis->label_manual);
        $this->assertFalse($analisis->perlu_review, 'Koreksi manusia adalah kepastian, bukan hasil yang masih ragu.');
        $this->assertNotNull($analisis->dikoreksi_oleh);
    }

    public function test_mencabut_koreksi_mengembalikan_status_review_sesuai_keyakinan_model(): void
    {
        $analisis = $this->analisis([
            'keyakinan' => 0.4,
            'perlu_review' => false,
            'label_manual' => LabelSentimen::Positif,
        ]);

        $this->actingAs(User::factory()->create())
            ->put("/admin/analisis/{$analisis->id}", ['label_manual' => null]);

        $analisis->refresh();

        $this->assertNull($analisis->label_manual);
        $this->assertSame(LabelSentimen::Negatif, $analisis->label_efektif);
        // Keyakinan 0,4 di bawah ambang 0,60 — statusnya kembali ragu.
        $this->assertTrue($analisis->perlu_review);
        $this->assertNull($analisis->dikoreksi_oleh);
    }
}
