<?php

namespace Tests\Feature;

use App\Jobs\LatihModelRelevansi;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\Media;
use App\Models\PelatihanModelRelevansi;
use App\Models\SnapshotDatasetRelevansi;
use App\Models\User;
use App\Services\ModelRelevansi\KandidatDataset;
use App\Services\ModelRelevansi\LayananRelevansi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Halaman Model Relevansi, dari kandidat sampai model aktif.
 *
 * Yang diuji di sini penjaga yang kegagalannya tidak menimbulkan galat sampai
 * berminggu-minggu kemudian: artikel perlu review yang bocor ke dataset sebagai
 * contoh tidak relevan, snapshot yang berubah setelah dipakai melatih, dan dua
 * model aktif sekaligus yang membuat prediksi bergantung urutan baris.
 *
 * Layanan IndoBERT dipalsukan seluruhnya. Yang ada di sisi Laravel memang bukan
 * pelatihan, melainkan penyerahan dataset dan penyalinan status, dan keduanya
 * bisa salah tanpa satu pun bobot ikut dihitung. Menjalankan pelatihan
 * sungguhan di dalam suite berarti mengunduh setengah gigabita bobot lalu
 * menunggu belasan menit untuk menguji dua puluh baris kode PHP.
 */
class ModelRelevansiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<string, mixed> Balasan `/status` yang sedang dipakai palsu. */
    private array $kabar = [];

    /** Jawaban `/batal`. False meniru layanan yang tidak mengenal run itu. */
    private bool $batalDijawab = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['peran' => 'superadmin']);

        Storage::fake('local');

        // Satu detik, bukan lima. Job menunggu sejeda ini sebelum tiap tarikan
        // status, dan pada palsu yang langsung menjawab selesai, jeda bawaan
        // hanya menambah lima detik mati per test.
        config(['relevansi.jeda_polling' => 1]);

        $this->kabar = $this->kabarSelesai();

        // Balasan status dibaca lewat closure, bukan ditanam sebagai nilai.
        // `Http::fake()` yang dipanggil kedua kali menggabungkan stub dan yang
        // pertama tetap menang, jadi test yang perlu balasan berbeda mengubah
        // `$this->kabar` alih-alih mendaftarkan stub baru yang tidak akan
        // pernah terpakai.
        Http::fake([
            '*/health' => Http::response([
                'status' => 'ok',
                'perangkat' => 'CPU, 4 thread',
                'torch' => '2.5.1',
                'transformers' => '4.48.0',
                'sedang_melatih' => false,
            ]),
            '*/latih' => Http::response(['diterima' => true, 'perangkat' => 'CPU, 4 thread'], 202),
            '*/batal/*' => fn () => Http::response(['dibatalkan' => $this->batalDijawab]),
            '*/status/*' => fn () => Http::response($this->kabar),
            '*/prediksi' => Http::response([
                'label' => 'relevan',
                'probabilitas_relevan' => 0.93,
                'probabilitas_tidak_relevan' => 0.07,
                'confidence' => 0.86,
                'inferensi_ms' => 120,
            ]),
        ]);
    }

    /**
     * Balasan `/status` untuk pelatihan yang sudah tuntas.
     *
     * Bentuknya sengaja sama persis dengan yang dikirim `relevansi/main.py`.
     * Palsu yang bentuknya lebih longgar daripada aslinya akan membuat test
     * tetap hijau ketika job lupa menyalin satu kolom.
     *
     * @return array<string, mixed>
     */
    private function kabarSelesai(string $status = 'berhasil'): array
    {
        $berhasil = $status === 'berhasil';

        return [
            'run_id' => 1,
            'status' => $status,
            'tahap' => $berhasil ? 'Selesai' : ucfirst($status),
            'progres' => $berhasil ? 100 : 40,
            'epoch_berjalan' => 3,
            'estimasi_sisa_detik' => $berhasil ? 0 : 42,
            'riwayat_epoch' => [
                ['epoch' => 1, 'train_loss' => 0.62, 'val_loss' => 0.51, 'val_accuracy' => 0.78, 'val_f1' => 0.77],
                ['epoch' => 2, 'train_loss' => 0.34, 'val_loss' => 0.29, 'val_accuracy' => 0.9, 'val_f1' => 0.9],
                ['epoch' => 3, 'train_loss' => 0.21, 'val_loss' => 0.27, 'val_accuracy' => 0.92, 'val_f1' => 0.92],
            ],
            'metrik' => $berhasil
                ? ['accuracy' => 0.93, 'precision' => 0.93, 'recall' => 0.93, 'f1' => 0.93, 'jumlah_test' => 6]
                : null,
            'confusion_matrix' => $berhasil
                ? ['relevan' => ['relevan' => 3, 'tidak_relevan' => 0], 'tidak_relevan' => ['relevan' => 0, 'tidak_relevan' => 3]]
                : null,
            'laporan_klasifikasi' => $berhasil
                ? ['macro' => ['precision' => 0.93, 'recall' => 0.93, 'f1' => 0.93, 'support' => 6]]
                : null,
            'artefak_path' => $berhasil ? 'model-relevansi/pelatihan-1' : null,
            'perangkat' => 'CPU, 4 thread',
            'galat' => $berhasil ? null : 'Kehabisan memori saat memuat base model.',
            'selesai' => true,
        ];
    }

    public function test_walikota_tidak_bisa_membuka_halaman(): void
    {
        $walikota = User::factory()->create(['peran' => 'walikota']);

        $this->actingAs($walikota)
            ->get('/admin/model-relevansi')
            ->assertForbidden();
    }

    public function test_artikel_perlu_review_tidak_masuk_kandidat(): void
    {
        $this->artikel(1, true);
        $this->artikel(2, false);
        // Jawaban Gemini "tidak mau memutuskan" disimpan sebagai relevan = false,
        // jadi tanpa penyaringan status ia terhitung sebagai contoh tidak
        // relevan dan model belajar bahwa keraguan sama dengan penolakan.
        $this->artikel(3, false, 'perlu_review');
        // Artikel yang belum pernah dinilai sama sekali.
        $this->artikel(4, null, 'isi_diambil');
        // Isi terlalu pendek untuk dilatihkan.
        $this->artikel(5, true, 'selesai', 'pendek');

        $ringkasan = app(KandidatDataset::class)->ringkasan();

        $this->assertSame(2, $ringkasan['total']);
        $this->assertSame(1, $ringkasan['relevan']);
        $this->assertSame(1, $ringkasan['tidak_relevan']);
    }

    public function test_snapshot_dibagi_stratified_dan_bisa_diulang_dengan_seed_sama(): void
    {
        $this->kandidat(60, 60);

        $data = [
            'nama' => 'seimbang',
            'jumlah_total' => 100,
            'persen_relevan' => 50,
            'persen_tidak_relevan' => 50,
            'persen_train' => 80,
            'persen_validation' => 10,
            'persen_test' => 10,
            'random_seed' => 7,
        ];

        $this->actingAs($this->admin)
            ->post('/admin/model-relevansi/snapshot', $data)
            ->assertRedirect()
            ->assertSessionHas('sukses');

        $snapshot = SnapshotDatasetRelevansi::firstWhere('nama', 'seimbang');

        $this->assertSame(100, $snapshot->total);
        $this->assertSame(50, $snapshot->total_relevan);
        $this->assertSame(50, $snapshot->total_tidak_relevan);
        $this->assertSame(80, $snapshot->total_train);
        $this->assertSame(10, $snapshot->total_validation);
        $this->assertSame(10, $snapshot->total_test);

        // Stratified: kedua label terwakili di setiap bagian dengan proporsi
        // yang sama. Pembagian acak biasa bisa menaruh seluruh artikel relevan
        // di training dan meninggalkan test yang isinya satu kelas.
        foreach (['train' => 40, 'validation' => 5, 'test' => 5] as $split => $perLabel) {
            foreach (['relevan', 'tidak_relevan'] as $label) {
                $this->assertSame($perLabel, $snapshot->item()->where('split', $split)->where('label', $label)->count());
            }
        }

        $pertama = $snapshot->item()->orderBy('id')->pluck('artikel_id')->all();

        $this->actingAs($this->admin)
            ->post('/admin/model-relevansi/snapshot', [...$data, 'nama' => 'seimbang-ulang'])
            ->assertSessionHas('sukses');

        $kedua = SnapshotDatasetRelevansi::firstWhere('nama', 'seimbang-ulang')
            ->item()->orderBy('id')->pluck('artikel_id')->all();

        $this->assertSame($pertama, $kedua);
    }

    public function test_komposisi_yang_melebihi_kandidat_ditolak_dengan_penjelasan(): void
    {
        $this->kandidat(60, 10);

        $this->actingAs($this->admin)
            ->post('/admin/model-relevansi/snapshot', [
                'nama' => 'timpang',
                // Butuh 50 artikel tidak relevan, tersedia hanya 10.
                'jumlah_total' => 100,
                'persen_relevan' => 50,
                'persen_tidak_relevan' => 50,
                'persen_train' => 80,
                'persen_validation' => 10,
                'persen_test' => 10,
                'random_seed' => 1,
            ])
            ->assertSessionHasErrors('jumlah_total');

        $this->assertDatabaseCount('snapshot_dataset_relevansi', 0);
        $this->assertDatabaseCount('item_snapshot_relevansi', 0);
    }

    public function test_snapshot_tidak_berubah_saat_artikelnya_dinilai_ulang(): void
    {
        $this->kandidat(30, 30);

        $snapshot = app(KandidatDataset::class)->buat($this->resepSnapshot(), $this->admin);

        $item = $snapshot->item()->where('label', 'relevan')->first();

        // Penilaian ulang membalik jawaban Gemini, dan crawler menimpa isinya.
        AnalisisSentimen::where('artikel_id', $item->artikel_id)->update(['relevan' => false]);
        Artikel::withoutGlobalScopes()->where('id', $item->artikel_id)->update(['isi' => 'isi yang sudah berbeda']);

        $item->refresh();

        $this->assertSame('relevan', $item->label);
        $this->assertStringNotContainsString('sudah berbeda', $item->isi);
    }

    public function test_snapshot_yang_dipakai_pelatihan_tidak_bisa_dihapus(): void
    {
        $this->kandidat(30, 30);

        $snapshot = app(KandidatDataset::class)->buat($this->resepSnapshot(), $this->admin);
        $this->pelatihan($snapshot);

        $this->actingAs($this->admin)
            ->delete("/admin/model-relevansi/snapshot/{$snapshot->id}")
            ->assertSessionHas('galat');

        $this->assertDatabaseHas('snapshot_dataset_relevansi', ['id' => $snapshot->id]);
    }

    public function test_pelatihan_masuk_antrean_bukan_dijalankan_di_dalam_permintaan(): void
    {
        Queue::fake();

        $this->kandidat(30, 30);

        $snapshot = app(KandidatDataset::class)->buat($this->resepSnapshot(), $this->admin);

        $this->actingAs($this->admin)
            ->post('/admin/model-relevansi/pelatihan', [
                'nama' => 'model-uji',
                'snapshot_dataset_relevansi_id' => $snapshot->id,
                'base_model' => 'indobenchmark/indobert-base-p1',
                'epoch' => 3,
                'batch_size' => 8,
                'learning_rate' => 0.00002,
                'max_seq_length' => 128,
                'seed' => 1,
                'early_stopping' => null,
            ])
            ->assertSessionHas('sukses');

        $this->assertDatabaseHas('pelatihan_model_relevansi', [
            'nama' => 'model-uji',
            'status' => 'menunggu',
        ]);

        Queue::assertPushed(LatihModelRelevansi::class);
    }

    public function test_dataset_yang_dikirim_ke_layanan_mengikuti_pembagian_snapshot(): void
    {
        $this->kandidat(40, 40);

        $snapshot = app(KandidatDataset::class)->buat($this->resepSnapshot(), $this->admin);
        $pelatihan = $this->pelatihan($snapshot);

        (new LatihModelRelevansi($pelatihan->id))->handle(app(LayananRelevansi::class));

        // Yang benar-benar dikerjakan Laravel pada pelatihan adalah menyusun
        // muatan ini. Kalau ia salah, model tetap terlatih dan angkanya tetap
        // keluar, hanya saja dilatih pada data yang bukan isi snapshot.
        Http::assertSent(function ($permintaan) use ($snapshot, $pelatihan) {
            if (! str_ends_with($permintaan->url(), '/latih')) {
                return false;
            }

            $isi = $permintaan->data();

            return $isi['run_id'] === $pelatihan->id
                && count($isi['train']) === $snapshot->total_train
                && count($isi['validation']) === $snapshot->total_validation
                && count($isi['test']) === $snapshot->total_test
                && $isi['konfigurasi']['base_model'] === 'indobenchmark/indobert-base-p1'
                // Judul ikut di depan isi. Maximum sequence length memotong dari
                // belakang, jadi judul yang tertinggal di luar potongan berarti
                // kalimat paling padat sinyal tidak pernah terbaca model.
                && str_starts_with($isi['train'][0]['teks'], 'Berita nomor ');
        });
    }

    public function test_status_dari_layanan_disalin_ke_baris_pelatihan(): void
    {
        $this->kandidat(40, 40);

        $snapshot = app(KandidatDataset::class)->buat($this->resepSnapshot(), $this->admin);
        $pelatihan = $this->pelatihan($snapshot);

        (new LatihModelRelevansi($pelatihan->id))->handle(app(LayananRelevansi::class));

        $pelatihan->refresh();

        $this->assertSame('berhasil', $pelatihan->status, (string) $pelatihan->galat);
        $this->assertSame(100, $pelatihan->progres);
        $this->assertSame('model-relevansi/pelatihan-1', $pelatihan->artefak_path);
        $this->assertSame('CPU, 4 thread', $pelatihan->perangkat);
        $this->assertSame(0.93, $pelatihan->metrik['f1']);
        $this->assertCount(3, $pelatihan->riwayat_epoch);
        $this->assertNotNull($pelatihan->confusion_matrix);
        $this->assertNotNull($pelatihan->laporan_klasifikasi);
        $this->assertNotNull($pelatihan->selesai_at);

        // Snapshot ditandai terpakai hanya setelah pelatihannya berhasil.
        $this->assertSame('terpakai', $snapshot->fresh()->status);
    }

    public function test_pelatihan_yang_gagal_di_layanan_ditutup_dengan_pesannya(): void
    {
        $this->kandidat(40, 40);

        $this->kabar = $this->kabarSelesai('gagal');

        $snapshot = app(KandidatDataset::class)->buat($this->resepSnapshot(), $this->admin);
        $pelatihan = $this->pelatihan($snapshot);

        (new LatihModelRelevansi($pelatihan->id))->handle(app(LayananRelevansi::class));

        $pelatihan->refresh();

        $this->assertSame('gagal', $pelatihan->status);
        $this->assertStringContainsString('Kehabisan memori', (string) $pelatihan->galat);
        $this->assertNull($pelatihan->artefak_path);

        // Snapshot tidak boleh berubah menjadi terpakai hanya karena ada yang
        // mencoba memakainya.
        $this->assertSame('siap', $snapshot->fresh()->status);
    }

    public function test_model_bisa_diaktifkan_dan_diuji_dengan_teks(): void
    {
        $this->kandidat(40, 40);

        $snapshot = app(KandidatDataset::class)->buat($this->resepSnapshot(), $this->admin);
        $pelatihan = $this->latih($this->pelatihan($snapshot));

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/pelatihan/{$pelatihan->id}/aktifkan")
            ->assertSessionHas('sukses');

        $this->assertTrue($pelatihan->fresh()->aktif);

        $this->actingAs($this->admin)
            ->post('/admin/model-relevansi/uji', [
                'pelatihan_model_relevansi_id' => $pelatihan->id,
                'teks' => 'kendari walikota kendari pemerintah kota kendari membangun jalan di kendari',
            ])
            ->assertSessionHas('hasilUji');

        $this->assertDatabaseCount('uji_manual_relevansi', 1);
    }

    public function test_hanya_satu_model_yang_bisa_aktif(): void
    {
        $this->kandidat(40, 40);

        $snapshot = app(KandidatDataset::class)->buat($this->resepSnapshot(), $this->admin);

        $satu = $this->pelatihan($snapshot, 'model-satu');
        $dua = $this->pelatihan($snapshot, 'model-dua');

        foreach ([$satu, $dua] as $p) {
            $this->latih($p);
        }

        $this->actingAs($this->admin)->post("/admin/model-relevansi/pelatihan/{$satu->id}/aktifkan");
        $this->actingAs($this->admin)->post("/admin/model-relevansi/pelatihan/{$dua->id}/aktifkan");

        $this->assertFalse($satu->fresh()->aktif);
        $this->assertTrue($dua->fresh()->aktif);
        $this->assertSame(1, PelatihanModelRelevansi::where('aktif', true)->count());
    }

    public function test_model_aktif_tidak_bisa_dihapus(): void
    {
        $this->kandidat(40, 40);

        $snapshot = app(KandidatDataset::class)->buat($this->resepSnapshot(), $this->admin);
        $pelatihan = $this->latih($this->pelatihan($snapshot));

        $this->actingAs($this->admin)->post("/admin/model-relevansi/pelatihan/{$pelatihan->id}/aktifkan");

        $this->actingAs($this->admin)
            ->delete("/admin/model-relevansi/pelatihan/{$pelatihan->id}")
            ->assertSessionHas('galat');

        $this->assertDatabaseHas('pelatihan_model_relevansi', ['id' => $pelatihan->id]);
    }

    public function test_baris_yang_tertinggal_berjalan_bisa_ditutup_dari_layar(): void
    {
        $this->kandidat(30, 30);

        $snapshot = app(KandidatDataset::class)->buat($this->resepSnapshot(), $this->admin);
        $pelatihan = $this->pelatihan($snapshot);

        // Keadaan setelah container layanan di-restart di tengah pelatihan:
        // barisnya berkata berjalan, tetapi layanan sudah tidak mengenalnya dan
        // job penunggunya ikut mati. Tanpa penutupan di controller, halaman
        // menarik dirinya sendiri selamanya demi pelatihan yang tidak ada.
        $pelatihan->update(['status' => 'berjalan', 'tahap' => 'Melatih', 'progres' => 40]);

        $this->batalDijawab = false;

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/pelatihan/{$pelatihan->id}/batal")
            ->assertSessionHas('sukses');

        $pelatihan->refresh();

        $this->assertSame('dibatalkan', $pelatihan->status);
        $this->assertNotNull($pelatihan->selesai_at);
    }

    public function test_pembatalan_yang_diminta_sebelum_worker_jalan_dihormati(): void
    {
        $this->kandidat(30, 30);

        $snapshot = app(KandidatDataset::class)->buat($this->resepSnapshot(), $this->admin);
        $pelatihan = $this->pelatihan($snapshot);

        $this->actingAs($this->admin)
            ->post("/admin/model-relevansi/pelatihan/{$pelatihan->id}/batal")
            ->assertSessionHas('sukses');

        (new LatihModelRelevansi($pelatihan->id))->handle(app(LayananRelevansi::class));

        $this->assertSame('dibatalkan', $pelatihan->fresh()->status);
        $this->assertNull($pelatihan->fresh()->artefak_path);
    }

    /**
     * Menjalankan job sampai selesai lalu menaruh artefak palsunya di disk.
     *
     * Berkas manifest wajib ada. Aktivasi model memeriksanya sebelum menetapkan
     * model aktif, tepatnya supaya baris yang menunjuk ke direktori yang sudah
     * hilang tidak pernah bisa menjadi model yang dipakai.
     */
    private function latih(PelatihanModelRelevansi $pelatihan): PelatihanModelRelevansi
    {
        (new LatihModelRelevansi($pelatihan->id))->handle(app(LayananRelevansi::class));

        $pelatihan->refresh();

        Storage::disk('local')->put($pelatihan->artefak_path.'/manifest.json', '{"max_seq_length":128}');

        return $pelatihan;
    }

    /**
     * @return array<string, mixed>
     */
    private function resepSnapshot(): array
    {
        return [
            'nama' => 'resep-'.uniqid(),
            'jumlah_total' => 60,
            'persen_relevan' => 50,
            'persen_tidak_relevan' => 50,
            'persen_train' => 80,
            'persen_validation' => 10,
            'persen_test' => 10,
            'random_seed' => 3,
        ];
    }

    private function pelatihan(SnapshotDatasetRelevansi $snapshot, string $nama = 'model-uji'): PelatihanModelRelevansi
    {
        return PelatihanModelRelevansi::create([
            'nama' => $nama,
            'snapshot_dataset_relevansi_id' => $snapshot->id,
            'base_model' => 'indobenchmark/indobert-base-p1',
            'konfigurasi' => [
                'base_model' => 'indobenchmark/indobert-base-p1',
                'epoch' => 5,
                'batch_size' => 8,
                'learning_rate' => 0.00002,
                'max_seq_length' => 128,
                'seed' => 1,
                'early_stopping' => null,
            ],
            'dibuat_oleh' => $this->admin->id,
        ]);
    }

    private function kandidat(int $relevan, int $tidakRelevan): void
    {
        for ($i = 0; $i < $relevan; $i++) {
            $this->artikel($i + 1, true);
        }

        for ($i = 0; $i < $tidakRelevan; $i++) {
            $this->artikel($relevan + $i + 1, false);
        }
    }

    /**
     * Satu artikel beserta hasil penilaiannya.
     *
     * Isi kedua label sengaja memakai kosakata yang tidak beririsan. Yang diuji
     * bukan seberapa pintar model membaca berita sungguhan, melainkan bahwa
     * pipeline-nya benar-benar belajar dari teks yang diberikan. Data acak akan
     * membuat test lulus atau gagal bergantung seed.
     */
    private function artikel(int $nomor, ?bool $relevan, string $status = 'selesai', string $panjang = 'normal'): Artikel
    {
        $kata = $relevan
            ? 'kendari walikota pemerintah kota sulawesi tenggara pembangunan jalan warga kelurahan'
            : 'jakarta bursa saham nasional artis konser sepakbola eropa transfer pemain klub';

        $isi = $panjang === 'pendek' ? 'terlalu pendek' : str_repeat($kata.' ', 8);

        $artikel = Artikel::withoutGlobalScopes()->create([
            'media_id' => $this->media()->id,
            'judul' => "Berita nomor {$nomor}",
            'url' => "https://contoh.test/{$nomor}",
            'url_kanonik' => "https://contoh.test/{$nomor}",
            'isi' => $isi,
            'diambil_at' => now(),
            'status_proses' => $status,
        ]);

        if ($relevan !== null) {
            AnalisisSentimen::create([
                'artikel_id' => $artikel->id,
                'relevan' => $relevan,
                'provider' => 'gemini',
                'model_versi' => 'uji-1.0',
                'dianalisis_at' => now(),
            ]);
        }

        return $artikel;
    }

    private ?Media $media = null;

    private function media(): Media
    {
        return $this->media ??= Media::create([
            'nama' => 'Media Uji',
            'slug' => 'media-uji',
            'jenis' => 'online',
            'tier' => 'lokal',
            'domain' => 'contoh.test',
        ]);
    }
}
