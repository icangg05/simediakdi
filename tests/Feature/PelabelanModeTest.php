<?php

namespace Tests\Feature;

use App\Enums\LabelSentimen;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\GoldSet;
use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mode terarah mengumpulkan contoh kelas langka.
 *
 * Berita negatif hanya 4,5% dari pasangan relevan pada korpus nyata, jadi
 * sampel acak murni tidak akan pernah memuat cukup banyak untuk mengukur
 * F1 negatif, 250 label acak menghasilkan sekitar tujuh contoh negatif.
 */
class PelabelanModeTest extends TestCase
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

    private function artikel(?LabelSentimen $label, bool $relevan = true, bool $perluReview = false): Artikel
    {
        static $n = 0;
        $n++;

        $media = Media::firstOrCreate(
            ['slug' => 'kp'],
            ['nama' => 'KP', 'domain' => 'kp.test'],
        );

        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $media->id,
            'judul' => "Berita {$n}",
            'url' => "https://kp.test/{$n}",
            'url_kanonik' => "https://kp.test/{$n}",
            'isi' => "Isi berita {$n}.",
            'diambil_at' => now(),
        ]);

        if ($label !== null || ! $relevan) {
            AnalisisSentimen::create([
                'artikel_id' => $artikel->id,
                'konteks_pantauan_id' => $this->konteks->id,
                'relevan' => $relevan,
                'label_model' => $label,
                'keyakinan' => $perluReview ? 0.4 : 0.99,
                'perlu_review' => $perluReview,
            ]);
        }

        return $artikel;
    }

    /** @return array<string, mixed> */
    private function buka(string $mode): array
    {
        return $this->actingAs($this->pelabel)
            ->get("/admin/pelabelan?konteks={$this->konteks->id}&ronde=1&mode={$mode}")
            ->viewData('page')['props'];
    }

    public function test_mode_negatif_hanya_menyodorkan_artikel_yang_ditebak_negatif(): void
    {
        $negatif = $this->artikel(LabelSentimen::Negatif);
        $this->artikel(LabelSentimen::Positif);
        $this->artikel(LabelSentimen::Netral);

        $this->assertSame($negatif->id, $this->buka('negatif')['tugas']['artikel']['id']);
    }

    public function test_mode_ragu_hanya_menyodorkan_artikel_berkeyakinan_rendah(): void
    {
        $this->artikel(LabelSentimen::Positif);
        $ragu = $this->artikel(LabelSentimen::Netral, perluReview: true);

        $this->assertSame($ragu->id, $this->buka('ragu')['tugas']['artikel']['id']);
    }

    /**
     * Beban terbesar pelabel di konteks sempit bukan menilai, tapi menekan
     * "tidak relevan" berulang kali, 85% artikel tidak relevan di sana.
     */
    public function test_mode_relevan_melewati_artikel_yang_dinilai_tidak_relevan(): void
    {
        $this->artikel(null, relevan: false);
        $relevan = $this->artikel(LabelSentimen::Netral);

        $this->assertSame($relevan->id, $this->buka('relevan')['tugas']['artikel']['id']);
    }

    public function test_mode_acak_tetap_menyodorkan_artikel_yang_belum_dianalisis(): void
    {
        // Tanpa baris analisis sama sekali, hanya mode acak yang memuatnya,
        // dan itu memang sampel yang mewakili keseluruhan.
        $belum = $this->artikel(null);

        $this->assertSame($belum->id, $this->buka('acak')['tugas']['artikel']['id']);
        $this->assertNull($this->buka('relevan')['tugas']);
    }

    /**
     * Mode harus bertahan setelah menyimpan label. Sempat tidak: `store()`
     * membacanya dari query string, sedangkan form POST tidak membawa query,
     * jadi pelabel terlempar kembali ke acak setiap kali menekan label.
     */
    public function test_mode_bertahan_setelah_menyimpan_label(): void
    {
        $artikel = $this->artikel(LabelSentimen::Negatif);

        $tujuan = $this->actingAs($this->pelabel)->post('/admin/pelabelan', [
            'artikel_id' => $artikel->id,
            'konteks_pantauan_id' => $this->konteks->id,
            'relevan_gold' => true,
            'label_gold' => 'negatif',
            'ronde' => 1,
            'mode' => 'negatif',
        ])->headers->get('Location');

        $this->assertStringContainsString('mode=negatif', $tujuan);
    }

    public function test_mode_tak_dikenal_ditolak_saat_menyimpan(): void
    {
        $artikel = $this->artikel(LabelSentimen::Netral);

        $this->actingAs($this->pelabel)->post('/admin/pelabelan', [
            'artikel_id' => $artikel->id,
            'konteks_pantauan_id' => $this->konteks->id,
            'relevan_gold' => true,
            'label_gold' => 'netral',
            'ronde' => 1,
            'mode' => 'ngawur',
        ])->assertSessionHasErrors('mode');
    }

    public function test_mode_tak_dikenal_jatuh_ke_acak(): void
    {
        $this->artikel(null);

        $this->assertSame('acak', $this->buka('ngawur')['mode']);
    }

    public function test_sisa_per_mode_dihitung_agar_mode_kosong_terlihat_lebih_dulu(): void
    {
        $this->artikel(LabelSentimen::Negatif);
        $this->artikel(LabelSentimen::Positif);
        $this->artikel(null, relevan: false);

        $sisa = $this->buka('acak')['sisaPerMode'];

        $this->assertSame(3, $sisa['acak']);
        $this->assertSame(2, $sisa['relevan']);
        $this->assertSame(1, $sisa['negatif']);
        $this->assertSame(0, $sisa['ragu']);
    }

    public function test_artikel_yang_sudah_dilabeli_hilang_dari_mode_mana_pun(): void
    {
        $negatif = $this->artikel(LabelSentimen::Negatif);

        GoldSet::create([
            'artikel_id' => $negatif->id,
            'konteks_pantauan_id' => $this->konteks->id,
            'label_gold' => LabelSentimen::Negatif,
            'relevan_gold' => true,
            'dilabeli_oleh' => $this->pelabel->id,
            'dilabeli_at' => now(),
            'ronde' => 1,
        ]);

        $this->assertNull($this->buka('negatif')['tugas']);
        $this->assertSame(0, $this->buka('acak')['sisaPerMode']['negatif']);
    }

    /** Membuka kembali label lama harus tetap bekerja di mode terarah. */
    public function test_membuka_artikel_lama_tetap_bisa_meski_mode_terarah(): void
    {
        $negatif = $this->artikel(LabelSentimen::Negatif);

        GoldSet::create([
            'artikel_id' => $negatif->id,
            'konteks_pantauan_id' => $this->konteks->id,
            'label_gold' => LabelSentimen::Negatif,
            'relevan_gold' => true,
            'dilabeli_oleh' => $this->pelabel->id,
            'dilabeli_at' => now(),
            'ronde' => 1,
        ]);

        $props = $this->actingAs($this->pelabel)
            ->get("/admin/pelabelan?konteks={$this->konteks->id}&ronde=1&mode=negatif&artikel={$negatif->id}")
            ->viewData('page')['props'];

        $this->assertSame($negatif->id, $props['tugas']['artikel']['id']);
        $this->assertSame('negatif', $props['tugas']['labelTersimpan']['label']);
    }
}
