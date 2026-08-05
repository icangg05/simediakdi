<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\SampelRelevansi;
use App\Models\SnapshotDatasetRelevansi;
use App\Models\User;
use App\Services\Relevance\RelevanceDatasetExporter;
use App\Services\Relevance\RelevanceSnapshotService;
use App\Services\Relevance\RelevanceSplitValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Snapshot dataset: pembagian per grup duplikat, kebocoran, dan penguncian.
 *
 * Kebocoran adalah satu-satunya kesalahan di laboratorium ini yang membuat
 * model terlihat lebih baik daripada sebenarnya. Kesalahan lain menurunkan
 * angka dan langsung terlihat; kebocoran menaikkannya, jadi tidak ada yang
 * curiga sampai model dipakai di produksi.
 */
class SnapshotRelevansiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['peran' => 'superadmin']);
        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);
    }

    public function test_snapshot_membagi_sampel_ke_tiga_split(): void
    {
        $this->banyakSampel(100, 'relevan');
        $this->banyakSampel(100, 'tidak_relevan');

        $snapshot = $this->buat();

        $this->assertSame(200, $snapshot->item()->count());
        $this->assertSame(160, $snapshot->total_train);
        $this->assertSame(20, $snapshot->total_validation);
        $this->assertSame(20, $snapshot->total_test);
        $this->assertSame('draft', $snapshot->status);
    }

    /**
     * Stratifikasi bukan kemewahan. Pembagian acak murni bisa menghasilkan test
     * set yang isinya satu kelas saja, dan presisi yang diukur di sana tidak
     * menggambarkan apa pun kecuali keberuntungan pengacakan.
     */
    public function test_setiap_split_menjaga_keseimbangan_kelas(): void
    {
        $this->banyakSampel(100, 'relevan');
        $this->banyakSampel(100, 'tidak_relevan');

        $snapshot = $this->buat();

        foreach (['train', 'validation', 'test'] as $split) {
            $relevan = $snapshot->item()->where('split', $split)->where('label_at_snapshot', 'relevan')->count();
            $total = $snapshot->item()->where('split', $split)->count();

            $this->assertEqualsWithDelta(0.5, $relevan / $total, 0.1, "Split {$split} timpang.");
        }
    }

    /**
     * Aturan paling penting di seluruh pembagian data. Salinan berita yang sama
     * tidak boleh tersebar antara data latih dan data uji.
     */
    public function test_anggota_satu_grup_duplikat_jatuh_di_split_yang_sama(): void
    {
        // Cukup besar supaya validation dan test melewati ambang minimalnya,
        // kalau tidak, temuan "split terlalu kecil" ikut muncul dan menutupi
        // apa yang sedang diuji di sini.
        $this->banyakSampel(120, 'relevan');
        $this->banyakSampel(120, 'tidak_relevan');

        // Satu berita disalin lima media, seluruhnya menunjuk grup yang sama.
        $induk = $this->sampel('Rilis Pemkot', 'relevan');
        $induk->update(['duplicate_group_id' => $induk->id]);

        for ($i = 0; $i < 4; $i++) {
            $this->sampel("Salinan rilis {$i}", 'relevan')->update(['duplicate_group_id' => $induk->id]);
        }

        $snapshot = $this->buat();

        $split = $snapshot->item()->where('duplicate_group_id', $induk->id)->pluck('split')->unique();

        $this->assertCount(1, $split, 'Grup duplikat tersebar di lebih dari satu split.');
        $this->assertSame([], app(RelevanceSplitValidator::class)->periksa($snapshot));
    }

    /** Benih yang sama harus menghasilkan pembagian yang sama persis. */
    public function test_snapshot_dapat_direproduksi_dengan_benih_yang_sama(): void
    {
        $this->banyakSampel(60, 'relevan');
        $this->banyakSampel(60, 'tidak_relevan');

        $service = app(RelevanceSnapshotService::class);

        $pertama = $service->buat(['nama' => 'uji', 'random_seed' => 7], $this->admin);
        $kedua = $service->buat(['nama' => 'uji', 'random_seed' => 7], $this->admin);

        $this->assertSame(
            $service->manifestHash($pertama),
            $service->manifestHash($kedua),
            'Benih yang sama menghasilkan pembagian yang berbeda.',
        );
    }

    public function test_kebocoran_isi_identik_terdeteksi(): void
    {
        $this->banyakSampel(60, 'relevan');
        $this->banyakSampel(60, 'tidak_relevan');

        // Dua sampel berisi teks sama persis tetapi grup duplikatnya berbeda,
        // pola siaran pers yang lolos deduplikasi.
        foreach (['https://kp.test/x', 'https://kp.test/y'] as $i => $url) {
            SampelRelevansi::create([
                'sumber_dataset' => 'crawler',
                'judul' => 'Siaran pers Pemkot Kendari',
                'isi' => 'Isi siaran pers yang sama persis.',
                'url' => $url,
                'media_id' => $this->media->id,
                'label_manual' => 'relevan',
                'status_label' => 'sudah_dilabeli',
                'duplicate_group_id' => 90000 + $i,
            ]);
        }

        $snapshot = $this->buat();
        $temuan = app(RelevanceSplitValidator::class)->periksa($snapshot);
        $jenis = array_column($temuan, 'jenis');

        // Hanya bocor kalau keduanya benar-benar jatuh di split berbeda.
        $split = $snapshot->item()
            ->whereIn('duplicate_group_id', [90000, 90001])
            ->pluck('split')->unique();

        if ($split->count() > 1) {
            $this->assertContains('isi_sama_lintas_split', $jenis);
        } else {
            $this->assertNotContains('isi_sama_lintas_split', $jenis);
        }
    }

    public function test_snapshot_dengan_kebocoran_tidak_bisa_dikunci(): void
    {
        // Terlalu sedikit sampel, jadi validator melaporkan split terlalu kecil.
        $this->banyakSampel(5, 'relevan');
        $this->banyakSampel(5, 'tidak_relevan');

        $snapshot = $this->buat();

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/snapshot/{$snapshot->id}/kunci")
            ->assertRedirect();

        $this->assertSame('draft', $snapshot->fresh()->status);
    }

    /**
     * Mengunci membekukan isinya dan menandai anggota test agar tidak bisa
     * diubah pelabelan biasa.
     */
    public function test_mengunci_snapshot_menyimpan_manifest_dan_mengunci_test_set(): void
    {
        $this->banyakSampel(100, 'relevan');
        $this->banyakSampel(100, 'tidak_relevan');

        $snapshot = $this->buat();

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/snapshot/{$snapshot->id}/kunci")
            ->assertRedirect();

        $snapshot->refresh();

        $this->assertSame('locked', $snapshot->status);
        $this->assertNotNull($snapshot->manifest_hash);
        $this->assertSame($this->admin->id, $snapshot->locked_by);

        $idTest = $snapshot->item()->where('split', 'test')->pluck('sampel_relevansi_id');

        $this->assertSame(
            $idTest->count(),
            SampelRelevansi::whereIn('id', $idTest)->where('status_label', 'terkunci_test')->count(),
        );
    }

    /**
     * Test set snapshot lama tetap jadi test di snapshot berikutnya.
     *
     * Tanpa ini pembagian diacak ulang dari nol tiap snapshot, dan F1 dua model
     * diukur dengan penggaris yang berbeda: kenaikan angka tidak bisa dibedakan
     * dari test set yang kebetulan lebih mudah. Anggota lama tidak boleh bocor
     * ke train, karena di situlah kebocoran menaikkan angka tanpa ada yang
     * curiga.
     */
    public function test_test_set_terkunci_dipertahankan_di_snapshot_berikutnya(): void
    {
        $this->banyakSampel(100, 'relevan');
        $this->banyakSampel(100, 'tidak_relevan');

        $pertama = $this->buat();

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/snapshot/{$pertama->id}/kunci")
            ->assertRedirect();

        $idTest = $pertama->item()->where('split', 'test')->pluck('sampel_relevansi_id')->sort()->values();

        // Dataset tumbuh sebelum snapshot kedua dibuat.
        $this->banyakSampel(40, 'relevan');
        $this->banyakSampel(40, 'tidak_relevan');

        $kedua = $this->buat();

        $this->assertSame(
            $idTest->all(),
            $kedua->item()->where('split', 'test')->pluck('sampel_relevansi_id')->sort()->values()->all(),
            'Test set berubah antar snapshot, jadi metrik dua model tidak bisa dibandingkan.',
        );

        $this->assertSame(
            0,
            $kedua->item()->whereIn('sampel_relevansi_id', $idTest)->whereIn('split', ['train', 'validation'])->count(),
            'Sampel test lama bocor ke data latih.',
        );

        // Seluruh sampel baru masuk train atau validation, tidak ada yang
        // diam-diam jatuh ke test lewat sisa pembulatan.
        $this->assertSame(280, $kedua->item()->count());
        $this->assertSame(280 - $idTest->count(), $kedua->total_train + $kedua->total_validation);
    }

    public function test_sampel_tanpa_label_tidak_pernah_masuk_snapshot(): void
    {
        $this->banyakSampel(60, 'relevan');
        $this->banyakSampel(60, 'tidak_relevan');

        SampelRelevansi::create([
            'sumber_dataset' => 'crawler',
            'judul' => 'Belum diputuskan',
            'isi' => 'Isi.',
            'media_id' => $this->media->id,
        ]);

        $snapshot = $this->buat();

        $this->assertSame(120, $snapshot->item()->count());
    }

    public function test_draft_bisa_dihapus_beserta_itemnya(): void
    {
        $this->banyakSampel(60, 'relevan');
        $this->banyakSampel(60, 'tidak_relevan');

        $snapshot = $this->buat();

        $this->actingAs($this->admin)
            ->delete("/admin/model-relevansi/snapshot/{$snapshot->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('snapshot_dataset_relevansi', ['id' => $snapshot->id]);
        $this->assertDatabaseMissing('item_snapshot_dataset_relevansi', [
            'snapshot_dataset_relevansi_id' => $snapshot->id,
        ]);
    }

    /**
     * Snapshot terkunci adalah catatan tentang data apa yang dipakai satu
     * eksperimen. Menghapusnya berarti membuang satu-satunya cara menjelaskan
     * angka evaluasi yang sudah terlanjur dilaporkan.
     */
    public function test_snapshot_terkunci_tidak_bisa_dihapus(): void
    {
        $this->banyakSampel(100, 'relevan');
        $this->banyakSampel(100, 'tidak_relevan');

        $snapshot = $this->buat();
        $this->actingAs($this->admin)->post("/admin/model-relevansi/snapshot/{$snapshot->id}/kunci");

        $this->actingAs($this->admin)
            ->delete("/admin/model-relevansi/snapshot/{$snapshot->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('snapshot_dataset_relevansi', [
            'id' => $snapshot->id,
            'status' => 'locked',
        ]);
    }

    /** Menghapus draft tidak boleh menyentuh sampel yang dirujuknya. */
    public function test_menghapus_draft_tidak_menghapus_sampelnya(): void
    {
        $this->banyakSampel(60, 'relevan');
        $this->banyakSampel(60, 'tidak_relevan');

        $snapshot = $this->buat();

        $this->actingAs($this->admin)->delete("/admin/model-relevansi/snapshot/{$snapshot->id}");

        $this->assertSame(120, SampelRelevansi::layakLatih()->count());
    }

    /**
     * Berkas ekspor ikut dibuang bersama snapshot-nya.
     *
     * JSONL di disk tidak dijaga database mana pun, dan ia memuat judul serta
     * isi artikel selengkapnya. Meninggalkannya bukan cuma soal disk: itu
     * salinan isi berita yang tidak lagi ditunjuk apa pun dan tidak akan
     * pernah ditinjau siapa pun.
     */
    public function test_menghapus_draft_ikut_membuang_dataset_ekspornya(): void
    {
        $this->banyakSampel(120);
        $this->banyakSampel(120, 'tidak_relevan');

        $snapshot = $this->buat();
        $direktori = app(RelevanceDatasetExporter::class)->direktori($snapshot);

        // Draft tidak bisa diekspor, jadi berkasnya dibuat langsung untuk
        // meniru sisa ekspor dari snapshot yang pernah terkunci.
        File::ensureDirectoryExists($direktori);
        File::put($direktori.'/train.jsonl', '{}');

        $this->actingAs($this->admin)->delete("/admin/model-relevansi/snapshot/{$snapshot->id}");

        $this->assertDirectoryDoesNotExist($direktori);
    }

    public function test_peran_walikota_tidak_bisa_membuat_snapshot(): void
    {
        $walikota = User::factory()->create(['peran' => 'walikota']);

        $this->actingAs($walikota)
            ->post('/admin/model-relevansi/snapshot', [
                'nama' => 'coba',
                'strategi_sampling' => 'natural_distribution',
                'random_seed' => 42,
                'persen_train' => 80,
                'persen_validation' => 10,
                'persen_test' => 10,
            ])
            ->assertForbidden();
    }

    private function buat(int $seed = 42): SnapshotDatasetRelevansi
    {
        return app(RelevanceSnapshotService::class)->buat(
            ['nama' => 'uji', 'random_seed' => $seed],
            $this->admin,
        );
    }

    private function banyakSampel(int $jumlah, string $label = 'relevan'): void
    {
        for ($i = 0; $i < $jumlah; $i++) {
            $this->sampel("Artikel {$label} {$i}", $label);
        }
    }

    private function sampel(string $judul, string $label): SampelRelevansi
    {
        $sampel = SampelRelevansi::create([
            'sumber_dataset' => 'crawler',
            'judul' => $judul,
            'isi' => "Isi dari {$judul}.",
            'url' => 'https://kp.test/'.md5($judul),
            'media_id' => $this->media->id,
            'label_manual' => $label,
            'status_label' => 'sudah_dilabeli',
        ]);

        $sampel->update(['duplicate_group_id' => $sampel->id]);

        return $sampel;
    }
}
