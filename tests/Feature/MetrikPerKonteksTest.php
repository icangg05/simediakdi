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
 * Angka gabungan menyembunyikan selisih yang menentukan tindakan.
 *
 * Pada pengukuran nyata, presisi relevansi 87,7% di satu konteks dan 51,1% di
 * konteks lain, sedangkan gabungannya 63,2%, angka yang tidak menggambarkan
 * keduanya dan tidak memberi tahu konteks mana kata kuncinya perlu diperketat.
 */
class MetrikPerKonteksTest extends TestCase
{
    use RefreshDatabase;

    private User $pelabel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pelabel = User::factory()->create();
        Media::create(['nama' => 'KP', 'slug' => 'kp', 'domain' => 'kp.test']);
    }

    private function konteks(string $nama, int $urutan): KonteksPantauan
    {
        return KonteksPantauan::create([
            'nama' => $nama,
            'slug' => str($nama)->slug()->value(),
            'urutan' => $urutan,
        ]);
    }

    private function pasangan(
        KonteksPantauan $konteks,
        LabelSentimen $gold,
        LabelSentimen $prediksi,
        bool $goldRelevan = true,
        bool $modelRelevan = true,
    ): void {
        static $n = 0;
        $n++;

        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => Media::first()->id,
            'judul' => "Berita {$n}",
            'url' => "https://kp.test/{$n}",
            'url_kanonik' => "https://kp.test/{$n}",
            'isi' => 'Isi.',
            'diambil_at' => now(),
        ]);

        AnalisisSentimen::create([
            'artikel_id' => $artikel->id,
            'konteks_pantauan_id' => $konteks->id,
            'relevan' => $modelRelevan,
            'label_model' => $prediksi,
            'keyakinan' => 0.99,
        ]);

        GoldSet::create([
            'artikel_id' => $artikel->id,
            'konteks_pantauan_id' => $konteks->id,
            'relevan_gold' => $goldRelevan,
            'label_gold' => $gold,
            'dilabeli_oleh' => $this->pelabel->id,
            'dilabeli_at' => now(),
            'ronde' => 1,
        ]);
    }

    public function test_metrik_dipecah_per_konteks(): void
    {
        $bagus = $this->konteks('Konteks Bagus', 1);
        $buruk = $this->konteks('Konteks Buruk', 2);

        // Bagus: model selalu benar, dan tidak pernah salah sebut relevan.
        foreach ([LabelSentimen::Negatif, LabelSentimen::Netral, LabelSentimen::Positif] as $label) {
            $this->pasangan($bagus, $label, $label);
        }

        // Buruk: dua dari tiga yang disebut relevan sebenarnya tidak.
        $this->pasangan($buruk, LabelSentimen::Positif, LabelSentimen::Positif);
        $this->pasangan($buruk, LabelSentimen::Netral, LabelSentimen::Netral, goldRelevan: false);
        $this->pasangan($buruk, LabelSentimen::Netral, LabelSentimen::Netral, goldRelevan: false);

        $hasil = collect(app(EvaluatorModel::class)->metrikPerKonteks())->keyBy('konteks');

        $this->assertSame(1.0, $hasil['Konteks Bagus']['akurasi']);
        $this->assertSame(1.0, $hasil['Konteks Bagus']['presisi_relevansi']);

        // Sepertiga: satu benar dari tiga yang model sebut relevan.
        $this->assertEqualsWithDelta(0.333, $hasil['Konteks Buruk']['presisi_relevansi'], 0.001);
    }

    /**
     * F1 macro merata-ratakan tiga kelas dengan bobot sama, jadi kelas yang
     * tidak punya sampel menghasilkan F1 nol dan menyeret rata-ratanya. Tanpa
     * penanda, konteks berakurasi tinggi terlihat seperti model yang gagal.
     */
    public function test_kelas_tanpa_sampel_ditandai(): void
    {
        $konteks = $this->konteks('Hanya Positif', 1);

        foreach (range(1, 5) as $i) {
            $this->pasangan($konteks, LabelSentimen::Positif, LabelSentimen::Positif);
        }

        $hasil = app(EvaluatorModel::class)->metrikPerKonteks()[0];

        $this->assertSame(1.0, $hasil['akurasi'], 'Seluruh prediksi benar.');
        $this->assertSame(['negatif', 'netral'], $hasil['kelas_tanpa_sampel']);
        // F1 macro jauh di bawah akurasi justru karena dua kelas kosong.
        $this->assertLessThan(0.4, $hasil['f1_macro']);
        $this->assertSame(['negatif' => 0, 'netral' => 0, 'positif' => 5], $hasil['sampel_per_kelas']);
    }

    public function test_konteks_tanpa_gold_set_tidak_ikut_dilaporkan(): void
    {
        $terpakai = $this->konteks('Terpakai', 1);
        $this->konteks('Belum Dilabeli', 2);

        $this->pasangan($terpakai, LabelSentimen::Netral, LabelSentimen::Netral);

        $hasil = app(EvaluatorModel::class)->metrikPerKonteks();

        $this->assertCount(1, $hasil);
        $this->assertSame('Terpakai', $hasil[0]['konteks']);
    }

    public function test_halaman_evaluasi_menampilkan_rincian_per_konteks(): void
    {
        $satu = $this->konteks('Konteks A', 1);
        $dua = $this->konteks('Konteks B', 2);

        $this->pasangan($satu, LabelSentimen::Netral, LabelSentimen::Netral);
        $this->pasangan($dua, LabelSentimen::Positif, LabelSentimen::Positif);

        $this->actingAs($this->pelabel)
            ->get('/admin/evaluasi')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('perKonteks', 2));
    }
}
