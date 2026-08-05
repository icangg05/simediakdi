<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\SampelRelevansi;
use App\Models\User;
use App\Services\Relevance\RelevanceSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menumbuhkan test set evaluasi dengan sampel acak.
 *
 * Yang dijaga di sini bukan sekadar perintahnya berjalan, melainkan bahwa
 * sampel yang dikunci benar-benar sampai ke split test di snapshot berikutnya.
 * Penanda yang tersimpan rapi tetapi tidak dibaca pembagi adalah kegagalan yang
 * tidak menimbulkan galat apa pun, dan baru ketahuan setelah satu putaran
 * pelatihan 90 menit terbuang.
 */
class TestSetRelevansiTest extends TestCase
{
    use RefreshDatabase;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);
    }

    public function test_siapkan_menandai_sampel_yang_belum_dilabeli(): void
    {
        $this->banyakSampel(10, null);
        $berlabel = $this->sampel('Sudah dilabeli', 'relevan');

        $this->artisan('relevance:test-set siapkan --jumlah=6')->assertSuccessful();

        $this->assertSame(6, $this->calon()->count());

        // Sampel berlabel tidak ikut. Seluruhnya berasal dari antrean
        // prioritas, jadi memilih dari sana mewarisi bias yang sedang
        // diperbaiki.
        $this->assertNull($berlabel->fresh()->metadata_sumber['evaluasi_acak'] ?? null);
    }

    public function test_kunci_hanya_mengambil_calon_yang_sudah_berlabel(): void
    {
        $this->banyakSampel(6, null);

        $this->artisan('relevance:test-set siapkan --jumlah=6')->assertSuccessful();

        $this->calon()->limit(4)->get()->each->update([
            'label_manual' => 'relevan',
            'status_label' => 'sudah_dilabeli',
        ]);

        $this->artisan('relevance:test-set kunci')->assertSuccessful();

        $this->assertSame(4, SampelRelevansi::where('status_label', 'terkunci_test')->count());
    }

    /**
     * Penanda harus benar-benar sampai ke split test, bukan berhenti di kolom.
     */
    public function test_sampel_yang_dikunci_masuk_split_test_di_snapshot_berikutnya(): void
    {
        $this->banyakSampel(60, 'relevan');
        $this->banyakSampel(60, 'tidak_relevan');
        $this->banyakSampel(4, null);

        $this->artisan('relevance:test-set siapkan --jumlah=4')->assertSuccessful();

        $this->calon()->get()->each->update([
            'label_manual' => 'relevan',
            'status_label' => 'sudah_dilabeli',
        ]);

        $this->artisan('relevance:test-set kunci')->assertSuccessful();

        $idTest = $this->calon()->pluck('id')->sort()->values();

        $snapshot = app(RelevanceSnapshotService::class)->buat(
            ['nama' => 'evaluasi', 'random_seed' => 42],
            User::factory()->create(['peran' => 'superadmin']),
        );

        $this->assertSame(
            $idTest->all(),
            $snapshot->item()->where('split', 'test')->pluck('sampel_relevansi_id')->sort()->values()->all(),
        );
    }

    /**
     * Ronde konsistensi tidak boleh menyentuh anggota test set.
     *
     * Ia mengosongkan `last_reviewed_at` supaya sampelnya dilabeli ulang, dan
     * label test yang berubah mengubah arti metrik seluruh model yang pernah
     * diukur di sana.
     */
    public function test_ronde_konsistensi_melewati_anggota_test_set(): void
    {
        $terkunci = $this->sampel('Anggota test', 'relevan');
        $terkunci->update(['status_label' => 'terkunci_test', 'last_reviewed_at' => now()]);

        $biasa = $this->sampel('Sampel biasa', 'relevan');
        $biasa->update(['status_label' => 'sudah_dilabeli', 'last_reviewed_at' => now()]);

        $this->artisan('relevance:konsistensi siapkan --jumlah=5')->assertSuccessful();

        $this->assertNull($terkunci->fresh()->metadata_sumber['ronde_konsistensi'] ?? null);
        $this->assertNotNull($terkunci->fresh()->last_reviewed_at);
        $this->assertNotNull($biasa->fresh()->metadata_sumber['ronde_konsistensi'] ?? null);
    }

    private function calon()
    {
        return SampelRelevansi::whereRaw("metadata_sumber->'evaluasi_acak' IS NOT NULL");
    }

    private function banyakSampel(int $jumlah, ?string $label): void
    {
        for ($i = 0; $i < $jumlah; $i++) {
            $this->sampel("Artikel {$label} {$i}", $label);
        }
    }

    private function sampel(string $judul, ?string $label): SampelRelevansi
    {
        return SampelRelevansi::create([
            'media_id' => $this->media->id,
            'judul' => $judul,
            'isi' => "Isi dari {$judul} yang cukup panjang untuk diperlakukan sebagai artikel.",
            'url' => 'https://kp.test/'.md5($judul),
            'sumber_dataset' => 'crawler',
            'label_manual' => $label,
            'status_label' => $label === null ? 'belum_dilabeli' : 'sudah_dilabeli',
        ]);
    }
}
