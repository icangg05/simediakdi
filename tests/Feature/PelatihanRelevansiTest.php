<?php

namespace Tests\Feature;

use App\Models\KonteksPantauan;
use App\Models\Media;
use App\Models\PelatihanModelRelevansi;
use App\Models\SampelRelevansi;
use App\Models\SnapshotDatasetRelevansi;
use App\Models\User;
use App\Models\VersiKonteksRelevansi;
use App\Services\Relevance\RelevanceDatasetExporter;
use App\Services\Relevance\RelevanceInputBuilder;
use App\Services\Relevance\RelevanceSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Bentuk input model dan ekspor dataset.
 *
 * `RelevanceInputBuilder` kecil tetapi paling berbahaya di laboratorium ini:
 * model yang dilatih dengan satu susunan lalu dipakai dengan susunan lain tetap
 * mengeluarkan angka yang tampak wajar dan salah, tanpa galat apa pun.
 */
class PelatihanRelevansiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Media $media;

    private KonteksPantauan $konteks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['peran' => 'superadmin']);
        $this->media = Media::create(['nama' => 'Kendari Pos', 'slug' => 'kp', 'domain' => 'kp.test']);

        $this->konteks = KonteksPantauan::create([
            'nama' => 'Pemerintah Kota Kendari',
            'slug' => 'pemkot-kendari',
            'utama' => true,
            'deskripsi_model' => 'Pemerintah Kota Kendari',
            'kata_kunci' => ['pemkot kendari'],
        ]);
    }

    /**
     * Konteks dipasangkan ke setiap sampel, jadi setiap tokennya dibayar di
     * seluruh dataset sekaligus. Paragraf aturan sepanjang 700 huruf pernah
     * memakan 137 dari 256 token dan menyisakan 116 untuk artikel yang
     * membutuhkan 264.
     */
    public function test_kalimat_konteks_yang_kepanjangan_ditolak(): void
    {
        $this->konteks->update(['deskripsi_model' => str_repeat('Aturan inklusi dan eksklusi yang panjang. ', 20)]);

        $this->expectException(InvalidArgumentException::class);

        app(RelevanceInputBuilder::class)->konteks($this->konteks->fresh());
    }

    /**
     * Bagian kosong dihilangkan, bukan ditulis kosong. Baris `Kategori:` tanpa
     * isi mengajari model bahwa baris itu biasanya kosong, lalu artikel yang
     * benar-benar punya kategori terlihat aneh baginya.
     */
    public function test_bagian_kosong_tidak_ikut_ditulis(): void
    {
        $teks = app(RelevanceInputBuilder::class)->artikel(
            'Pemkot Kendari perbaiki drainase',
            null,
            'Pemkot Kendari memperbaiki drainase di Kadia.',
            null,
            null,
            $this->konteks,
        );

        $this->assertStringContainsString('Judul: Pemkot Kendari perbaiki drainase', $teks);
        $this->assertStringNotContainsString('Kategori:', $teks);
        $this->assertStringNotContainsString('Tag:', $teks);
        $this->assertStringNotContainsString('Ringkasan:', $teks);
    }

    public function test_bagian_yang_terisi_ikut_ditulis(): void
    {
        $teks = app(RelevanceInputBuilder::class)->artikel(
            'Judul uji',
            'Ringkasan uji',
            'Pemkot Kendari menanggapi keluhan warga.',
            ['Kendari'],
            ['Pemkot Kendari'],
            $this->konteks,
        );

        foreach (['Judul:', 'Kategori: Kendari', 'Tag: Pemkot Kendari', 'Ringkasan: Ringkasan uji', 'Potongan isi terkait:'] as $bagian) {
            $this->assertStringContainsString($bagian, $teks);
        }
    }

    /**
     * Teks yang sama di bawah susunan berbeda tidak boleh menghasilkan cap jari
     * yang sama, kalau tidak, prediksi lama terlihat sebanding dengan yang baru.
     */
    public function test_input_hash_memperhitungkan_versi_susunan(): void
    {
        $builder = app(RelevanceInputBuilder::class);

        $this->assertSame(
            hash('sha256', RelevanceInputBuilder::VERSI."\nkonteks\nartikel"),
            $builder->inputHash('konteks', 'artikel'),
        );
    }

    public function test_snapshot_draft_tidak_bisa_diekspor(): void
    {
        $this->sampelBanyak(120);
        $snapshot = $this->snapshot();

        $this->expectException(RuntimeException::class);

        app(RelevanceDatasetExporter::class)->ekspor($snapshot);
    }

    /**
     * Label ditulis dari `label_at_snapshot`, bukan dari sampelnya.
     *
     * Sampel bisa dikoreksi kapan saja, dan snapshot yang mengikuti koreksi itu
     * berhenti menjadi snapshot: dua pelatihan atas snapshot yang sama akan
     * memakai data berbeda tanpa ada yang mengubah eksperimennya.
     */
    public function test_ekspor_memakai_label_yang_dibekukan(): void
    {
        // Cukup besar supaya validation dan test melewati ambang minimalnya.
        $this->sampelBanyak(240);
        $snapshot = $this->snapshot();
        app(RelevanceSnapshotService::class)->kunci($snapshot, $this->admin);

        // Seluruh label dibalik setelah snapshot dikunci.
        SampelRelevansi::query()->update(['label_manual' => 'tidak_relevan']);

        $hasil = app(RelevanceDatasetExporter::class)->ekspor($snapshot->fresh());
        $baris = array_map(
            fn ($b) => json_decode($b, true),
            array_filter(explode("\n", file_get_contents($hasil['berkas']['train']))),
        );

        $this->assertContains(1, array_column($baris, 'label'), 'Label relevan hilang setelah sampelnya diubah.');
    }

    public function test_ekspor_memetakan_relevan_ke_satu(): void
    {
        // Cukup besar supaya validation dan test melewati ambang minimalnya.
        $this->sampelBanyak(240);
        $snapshot = $this->snapshot();
        app(RelevanceSnapshotService::class)->kunci($snapshot, $this->admin);

        $hasil = app(RelevanceDatasetExporter::class)->ekspor($snapshot->fresh());
        $baris = array_map(
            fn ($b) => json_decode($b, true),
            array_filter(explode("\n", file_get_contents($hasil['berkas']['train']))),
        );

        foreach ($baris as $satu) {
            $sampel = SampelRelevansi::find($satu['id']);
            $diharapkan = $sampel->label_manual->value === 'relevan' ? 1 : 0;

            $this->assertSame($diharapkan, $satu['label'], "Pemetaan label sampel {$satu['id']} terbalik.");
            $this->assertSame('Pemerintah Kota Kendari', $satu['konteks']);
        }
    }

    /**
     * Pelatihan berhasil punya model kandidat yang menunjuk kepadanya, dan
     * menghapusnya meninggalkan model tanpa asal usul: tidak ada lagi cara
     * mengetahui snapshot dan konfigurasi apa yang menghasilkannya.
     */
    public function test_pelatihan_berhasil_tidak_bisa_dihapus_dari_riwayat(): void
    {
        $run = $this->pelatihan('selesai');

        $this->actingAs($this->admin)
            ->delete("/admin/model-relevansi/pelatihan/{$run->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('pelatihan_model_relevansi', ['id' => $run->id]);
    }

    public function test_pelatihan_gagal_bisa_dihapus_dari_riwayat(): void
    {
        $run = $this->pelatihan('gagal');

        $this->actingAs($this->admin)
            ->delete("/admin/model-relevansi/pelatihan/{$run->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('pelatihan_model_relevansi', ['id' => $run->id]);
    }

    private function pelatihan(string $status): PelatihanModelRelevansi
    {
        $this->sampelBanyak(240);
        $snapshot = $this->snapshot();
        app(RelevanceSnapshotService::class)->kunci($snapshot, $this->admin);

        $versiKonteks = VersiKonteksRelevansi::create([
            'nama' => 'Pemkot', 'versi' => 'v1', 'slug' => 'pemkot-v1',
            'deskripsi_manusia' => 'Pemkot', 'deskripsi_model' => 'Pemerintah Kota Kendari',
            'aturan_inklusi' => [], 'aturan_eksklusi' => [], 'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        return PelatihanModelRelevansi::create([
            'nama' => 'uji', 'base_model' => 'apriandito/indobert-relevancy-classifier',
            'snapshot_dataset_relevansi_id' => $snapshot->id,
            'versi_konteks_relevansi_id' => $versiKonteks->id,
            'versi_panduan_label' => '2.1', 'status' => $status,
            'configuration' => [], 'created_by' => $this->admin->id,
        ]);
    }

    private function snapshot(): SnapshotDatasetRelevansi
    {
        return app(RelevanceSnapshotService::class)->buat(['nama' => 'uji'], $this->admin);
    }

    private function sampelBanyak(int $jumlah): void
    {
        for ($i = 0; $i < $jumlah; $i++) {
            $label = $i % 2 === 0 ? 'relevan' : 'tidak_relevan';

            $sampel = SampelRelevansi::create([
                'sumber_dataset' => 'crawler',
                'judul' => "Artikel {$i}",
                'isi' => "Pemkot Kendari membahas perkara nomor {$i}. Kalimat kedua sebagai isi.",
                'url' => "https://kp.test/{$i}",
                'media_id' => $this->media->id,
                'label_manual' => $label,
                'status_label' => 'sudah_dilabeli',
            ]);

            $sampel->update(['duplicate_group_id' => $sampel->id]);
        }
    }
}
