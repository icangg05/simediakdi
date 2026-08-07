<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanPelatihanRelevansiRequest;
use App\Http\Requests\SimpanSnapshotRelevansiRequest;
use App\Jobs\LatihModelRelevansi;
use App\Models\PelatihanModelRelevansi;
use App\Models\SnapshotDatasetRelevansi;
use App\Models\UjiManualRelevansi;
use App\Services\ModelRelevansi\KandidatDataset;
use App\Services\ModelRelevansi\LayananRelevansi;
use App\Services\ModelRelevansi\PrediktorRelevansi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Model Relevansi, `/admin/model-relevansi`.
 *
 * Tiga tab, satu alur: menyiapkan dataset, melatih model di atasnya, lalu
 * mencobanya dengan teks yang diketik sendiri. Halamannya menarik dirinya
 * sendiri selama masih ada pelatihan yang berjalan, jadi seluruh method di sini
 * harus tetap murah.
 *
 * Seluruh rutenya terbatas superadmin. Yang bisa dilakukan dari halaman ini
 * bukan membaca angka, melainkan menentukan model mana yang berlaku, dan itu
 * bukan wewenang yang sama dengan membuka dashboard.
 */
class ModelRelevansiController extends Controller
{
    public function index(KandidatDataset $kandidat, LayananRelevansi $layanan): Response
    {
        return Inertia::render('admin/model-relevansi/Index', [
            'kandidat' => $kandidat->ringkasan(),
            'snapshot' => $this->daftarSnapshot(),
            'pelatihan' => $this->daftarPelatihan(),
            'riwayatUji' => $this->riwayatUji(),
            'opsi' => [
                'base_model' => LayananRelevansi::BASE_MODEL,
                'batas' => LayananRelevansi::BATAS,
            ],
            // Keadaan layanan model, di luar `only` polling supaya tidak
            // ditanyakan tiap tiga detik. Yang dicari halaman ini bukan
            // pemantauan layanan, melainkan penjelasan di muka kalau tombol
            // Mulai Pelatihan tidak akan bekerja.
            'layanan' => $layanan->kesehatan(),
            'diperbarui' => now()->toIso8601String(),
        ]);
    }

    public function simpanSnapshot(SimpanSnapshotRelevansiRequest $request, KandidatDataset $kandidat): RedirectResponse
    {
        $data = $request->validated();

        // Seed yang dikosongkan diisi sistem, bukan dibiarkan null. Kolomnya
        // ada supaya pengambilan acak bisa diulang, dan snapshot tanpa seed
        // tersimpan tidak bisa diulang oleh siapa pun termasuk yang membuatnya.
        $data['random_seed'] ??= random_int(1, 999999);

        $snapshot = $kandidat->buat($data, $request->user());

        return back()->with('sukses', "Snapshot {$snapshot->nama} dibuat, {$snapshot->total} artikel.")
            ->with('catatan', sprintf(
                'Training %d, validation %d, testing %d. Seed %d.',
                $snapshot->total_train,
                $snapshot->total_validation,
                $snapshot->total_test,
                $snapshot->random_seed,
            ));
    }

    public function hapusSnapshot(SnapshotDatasetRelevansi $snapshot): RedirectResponse
    {
        $terpakai = $snapshot->pelatihan()->count();

        if ($terpakai > 0) {
            return back()->with('galat', sprintf(
                'Snapshot %s masih dipakai %d pelatihan. Hapus pelatihannya lebih dulu, atau biarkan snapshot ini '
                .'agar angka evaluasi model tersebut tetap bisa ditelusuri.',
                $snapshot->nama,
                $terpakai,
            ));
        }

        $nama = $snapshot->nama;
        $snapshot->delete();

        return back()->with('sukses', "Snapshot {$nama} dihapus.");
    }

    public function simpanPelatihan(SimpanPelatihanRelevansiRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $pelatihan = PelatihanModelRelevansi::create([
            'nama' => $data['nama'],
            'snapshot_dataset_relevansi_id' => $data['snapshot_dataset_relevansi_id'],
            'base_model' => $data['base_model'],
            'konfigurasi' => [
                'base_model' => $data['base_model'],
                'epoch' => (int) $data['epoch'],
                'batch_size' => (int) $data['batch_size'],
                'learning_rate' => (float) $data['learning_rate'],
                'max_seq_length' => (int) $data['max_seq_length'],
                'seed' => (int) ($data['seed'] ?? random_int(1, 999999)),
                'early_stopping' => $data['early_stopping'] === null ? null : (int) $data['early_stopping'],
            ],
            'tahap' => 'Menunggu worker',
            'dibuat_oleh' => $request->user()->id,
        ]);

        LatihModelRelevansi::dispatch($pelatihan->id);

        return back()->with('sukses', "Pelatihan {$pelatihan->nama} masuk antrean.")
            ->with('catatan', 'Kemajuannya muncul di daftar pelatihan tanpa perlu memuat ulang halaman.');
    }

    /**
     * Menghentikan pelatihan seketika.
     *
     * Dua tindakan, dan keduanya perlu. Penanda `batal_diminta` mengurus
     * pelatihan yang masih mengantre dan belum pernah sampai ke layanan, dan
     * ia dibaca job sebelum satu byte pun dikirim. Panggilan langsung ke
     * layanan mengurus pelatihan yang sudah berjalan.
     *
     * Panggilannya dilakukan dari sini, bukan diserahkan ke putaran polling
     * job. Job baru menengok penandanya tiap lima detik, dan menunggu selama
     * itu membuat tombol yang seharusnya seketika terasa tidak berfungsi. Job
     * tetap memanggil batal juga pada putaran berikutnya, dan panggilan kedua
     * itu tidak berbahaya: layanan menjawab false untuk pelatihan yang sudah
     * berhenti.
     *
     * Sebelumnya pembatalan hanya menyetel penanda, dan layanan memeriksanya di
     * dalam callback Trainer. Akibatnya tombol ini tidak berbuat apa-apa selama
     * tahap memuat base model, yang untuk checkpoint besar berarti menunggui
     * unduhan 1,3 GB sampai selesai lebih dulu.
     */
    public function batalPelatihan(PelatihanModelRelevansi $pelatihan, LayananRelevansi $layanan): RedirectResponse
    {
        if (in_array($pelatihan->status, PelatihanModelRelevansi::SELESAI, true)) {
            return back()->with('galat', "Pelatihan {$pelatihan->nama} sudah selesai dan tidak bisa dibatalkan.");
        }

        $pelatihan->update(['batal_diminta' => true, 'tahap' => 'Menghentikan pelatihan']);

        if ($layanan->batal($pelatihan->id)) {
            return back()->with('sukses', "Pelatihan {$pelatihan->nama} dihentikan.")
                ->with('catatan', 'Tidak ada model yang tersimpan. Daftar diperbarui dalam beberapa detik.');
        }

        // Layanan tidak mengenal pelatihan ini, jadi tidak ada apa pun yang
        // sedang berjalan untuk dihentikan. Dua keadaan bermuara ke sini, dan
        // keduanya benar ditutup di tempat.
        //
        // Yang pertama biasa: pekerjaannya masih mengantre dan belum pernah
        // sampai ke layanan. Job akan berhenti sendiri begitu gilirannya tiba,
        // karena `handle()` keluar untuk baris yang statusnya bukan menunggu.
        //
        // Yang kedua yang menjadi alasan blok ini ada: baris yang tertinggal
        // berstatus berjalan setelah container layanan di-restart di tengah
        // pelatihan. Job penunggunya ikut mati bersama container, jadi tidak
        // ada lagi yang akan menutup barisnya. Tanpa ini, satu-satunya
        // penyelesaiannya adalah menyunting database dengan tangan, sementara
        // halaman terus menarik dirinya sendiri demi pelatihan yang sebenarnya
        // sudah tidak ada.
        $pelatihan->update([
            'status' => 'dibatalkan',
            'tahap' => 'Dibatalkan',
            'selesai_at' => now(),
        ]);

        return back()->with('sukses', "Pelatihan {$pelatihan->nama} dihentikan.")
            ->with('catatan', 'Layanan model tidak sedang mengerjakannya, jadi barisnya langsung ditutup.');
    }

    public function aktifkanPelatihan(PelatihanModelRelevansi $pelatihan): RedirectResponse
    {
        if ($pelatihan->status !== 'berhasil' || $pelatihan->artefak_path === null) {
            return back()->with('galat', 'Hanya pelatihan yang berhasil dan punya berkas model yang bisa diaktifkan.');
        }

        // Manifest yang diperiksa, bukan direktorinya. `exists()` pada disk
        // lokal menjawab false untuk direktori, jadi memeriksa jalur artefak
        // apa adanya akan menolak setiap model yang sebenarnya baik-baik saja.
        if (! Storage::disk('local')->exists($pelatihan->artefak_path.'/manifest.json')) {
            return back()->with('galat', "Berkas model {$pelatihan->nama} tidak ditemukan. Latih ulang model ini.");
        }

        // Dua tulisan dalam satu transaksi. Indeks unik parsial di database
        // menolak dua model aktif sekaligus, jadi mencabut yang lama harus
        // selesai sebelum yang baru dipasang.
        DB::transaction(function () use ($pelatihan) {
            PelatihanModelRelevansi::where('aktif', true)->update(['aktif' => false]);
            $pelatihan->update(['aktif' => true]);
        });

        return back()->with('sukses', "Model {$pelatihan->nama} ditetapkan sebagai model aktif.");
    }

    public function hapusPelatihan(PelatihanModelRelevansi $pelatihan): RedirectResponse
    {
        if ($pelatihan->aktif) {
            return back()->with('galat',
                "Model {$pelatihan->nama} sedang aktif. Aktifkan model lain lebih dulu sebelum menghapusnya."
            );
        }

        if (! in_array($pelatihan->status, PelatihanModelRelevansi::SELESAI, true)) {
            return back()->with('galat',
                "Pelatihan {$pelatihan->nama} masih berjalan. Batalkan dulu, baru hapus."
            );
        }

        // Artefak IndoBERT adalah direktori berisi bobot, tokenizer, dan
        // manifest, bukan satu berkas. `delete()` pada direktori tidak berbuat
        // apa-apa dan tidak mengeluh, jadi bobot ratusan megabita akan tertinggal
        // diam-diam sampai disk penuh.
        if ($pelatihan->artefak_path !== null) {
            Storage::disk('local')->deleteDirectory($pelatihan->artefak_path);
        }

        $nama = $pelatihan->nama;
        $pelatihan->delete();

        return back()->with('sukses', "Pelatihan {$nama} dihapus.");
    }

    /**
     * Seluruh angka evaluasi satu pelatihan sebagai satu berkas JSON.
     *
     * Isinya persis yang ada di layar, tanpa tambahan dan tanpa pengurangan.
     * Berkas evaluasi yang bentuknya berbeda dari layar adalah berkas yang
     * angkanya harus dicocokkan ulang tiap kali dipakai.
     */
    public function unduhEvaluasi(PelatihanModelRelevansi $pelatihan): JsonResponse
    {
        $pelatihan->load('snapshot');

        return response()->json([
            'nama' => $pelatihan->nama,
            'base_model' => $pelatihan->base_model,
            'konfigurasi' => $pelatihan->konfigurasi,
            'perangkat' => $pelatihan->perangkat,
            'snapshot' => [
                'nama' => $pelatihan->snapshot?->nama,
                'random_seed' => $pelatihan->snapshot?->random_seed,
                'total' => $pelatihan->snapshot?->total,
                'total_relevan' => $pelatihan->snapshot?->total_relevan,
                'total_tidak_relevan' => $pelatihan->snapshot?->total_tidak_relevan,
                'total_train' => $pelatihan->snapshot?->total_train,
                'total_validation' => $pelatihan->snapshot?->total_validation,
                'total_test' => $pelatihan->snapshot?->total_test,
            ],
            'metrik' => $pelatihan->metrik,
            'confusion_matrix' => $pelatihan->confusion_matrix,
            'laporan_klasifikasi' => $pelatihan->laporan_klasifikasi,
            'riwayat_epoch' => $pelatihan->riwayat_epoch,
            'mulai_at' => $pelatihan->mulai_at?->toIso8601String(),
            'selesai_at' => $pelatihan->selesai_at?->toIso8601String(),
        ], 200, [
            'Content-Disposition' => 'attachment; filename="evaluasi-'.$pelatihan->id.'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function uji(Request $request, PrediktorRelevansi $prediktor): RedirectResponse
    {
        $data = $request->validate([
            'pelatihan_model_relevansi_id' => ['required', 'integer', 'exists:pelatihan_model_relevansi,id'],
            'teks' => ['required', 'string', 'min:20', 'max:20000'],
        ], [
            'pelatihan_model_relevansi_id.required' => 'Pilih model yang akan diuji terlebih dahulu.',
            'teks.required' => 'Masukkan judul atau isi berita yang akan diuji.',
            'teks.min' => 'Teks terlalu pendek untuk dinilai. Masukkan sekurangnya 20 karakter.',
        ]);

        $model = PelatihanModelRelevansi::findOrFail($data['pelatihan_model_relevansi_id']);

        if ($model->status !== 'berhasil') {
            return back()->with('galat', "Model {$model->nama} belum selesai dilatih.");
        }

        try {
            $hasil = $prediktor->jalankan($model, $data['teks']);
        } catch (Throwable $e) {
            return back()->with('galat', $e->getMessage());
        }

        $baris = UjiManualRelevansi::create([
            'pelatihan_model_relevansi_id' => $model->id,
            'teks' => $data['teks'],
            'label_prediksi' => $hasil['label'],
            'probabilitas_relevan' => $hasil['probabilitas_relevan'],
            'probabilitas_tidak_relevan' => $hasil['probabilitas_tidak_relevan'],
            'confidence' => $hasil['confidence'],
            'inferensi_ms' => $hasil['inferensi_ms'],
            'dibuat_oleh' => $request->user()->id,
        ]);

        return back()->with('hasilUji', [
            'id' => $baris->id,
            'label' => $hasil['label'],
            'probabilitas_relevan' => round($hasil['probabilitas_relevan'], 4),
            'probabilitas_tidak_relevan' => round($hasil['probabilitas_tidak_relevan'], 4),
            'confidence' => round($hasil['confidence'], 4),
            'inferensi_ms' => $hasil['inferensi_ms'],
            'model' => $model->nama,
            'base_model' => $model->base_model,
            'diuji_at' => $baris->created_at->toIso8601String(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function daftarSnapshot(): array
    {
        return SnapshotDatasetRelevansi::query()
            ->with('pembuat:id,name')
            ->withCount('pelatihan')
            ->latest('id')
            ->get()
            ->map(fn (SnapshotDatasetRelevansi $s) => [
                'id' => $s->id,
                'nama' => $s->nama,
                'deskripsi' => $s->deskripsi,
                'status' => $s->status,
                'random_seed' => $s->random_seed,
                'total' => $s->total,
                'total_relevan' => $s->total_relevan,
                'total_tidak_relevan' => $s->total_tidak_relevan,
                'total_train' => $s->total_train,
                'total_validation' => $s->total_validation,
                'total_test' => $s->total_test,
                'persen_relevan' => $s->persen_relevan,
                'persen_tidak_relevan' => $s->persen_tidak_relevan,
                'persen_train' => $s->persen_train,
                'persen_validation' => $s->persen_validation,
                'persen_test' => $s->persen_test,
                'pelatihan_count' => $s->pelatihan_count,
                'pembuat' => $s->pembuat?->name,
                'dibuat_at' => $s->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function daftarPelatihan(): array
    {
        return PelatihanModelRelevansi::query()
            ->with(['snapshot:id,nama,total,total_train,total_validation,total_test', 'pembuat:id,name'])
            ->latest('id')
            ->get()
            ->map(fn (PelatihanModelRelevansi $p) => [
                'id' => $p->id,
                'nama' => $p->nama,
                'status' => $p->status,
                'tahap' => $p->tahap,
                'progres' => $p->progres,
                'epoch_berjalan' => $p->epoch_berjalan,
                'estimasi_sisa_detik' => $p->estimasi_sisa_detik,
                'base_model' => $p->base_model,
                'konfigurasi' => $p->konfigurasi,
                'metrik' => $p->metrik,
                'riwayat_epoch' => $p->riwayat_epoch,
                'confusion_matrix' => $p->confusion_matrix,
                'laporan_klasifikasi' => $p->laporan_klasifikasi,
                'perangkat' => $p->perangkat,
                'galat' => $p->galat,
                'aktif' => $p->aktif,
                'batal_diminta' => $p->batal_diminta,
                'artefak_path' => $p->artefak_path,
                'snapshot' => $p->snapshot ? [
                    'id' => $p->snapshot->id,
                    'nama' => $p->snapshot->nama,
                    'total' => $p->snapshot->total,
                    'total_train' => $p->snapshot->total_train,
                    'total_validation' => $p->snapshot->total_validation,
                    'total_test' => $p->snapshot->total_test,
                ] : null,
                'pembuat' => $p->pembuat?->name,
                'mulai_at' => $p->mulai_at?->toIso8601String(),
                'selesai_at' => $p->selesai_at?->toIso8601String(),
                'dibuat_at' => $p->created_at?->toIso8601String(),
                'durasi_detik' => $this->durasi($p),
            ])
            ->all();
    }

    /**
     * Durasi dihitung di server, bukan di browser.
     *
     * Jam di komputer pengguna bisa meleset beberapa menit dari jam server, dan
     * selisih itu terbaca sebagai pelatihan yang mulai di masa depan atau sudah
     * berjalan sejam padahal baru ditekan.
     */
    private function durasi(PelatihanModelRelevansi $p): ?int
    {
        if ($p->mulai_at === null) {
            return null;
        }

        return (int) $p->mulai_at->diffInSeconds($p->selesai_at ?? now(), absolute: true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function riwayatUji(): array
    {
        return UjiManualRelevansi::query()
            ->with(['pelatihan:id,nama,base_model', 'penguji:id,name'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (UjiManualRelevansi $u) => [
                'id' => $u->id,
                // Potongan saja untuk daftar. Teks utuhnya tetap tersimpan dan
                // bisa dijalankan ulang, tetapi mengirim seluruhnya ke layar
                // pada tiap tarikan polling adalah pemborosan yang tumbuh
                // bersama panjang artikel.
                'potongan' => str($u->teks)->limit(160)->value(),
                'label_prediksi' => $u->label_prediksi,
                'confidence' => round($u->confidence, 4),
                'probabilitas_relevan' => round($u->probabilitas_relevan, 4),
                'inferensi_ms' => $u->inferensi_ms,
                'model' => $u->pelatihan?->nama,
                'base_model' => $u->pelatihan?->base_model,
                'penguji' => $u->penguji?->name,
                'diuji_at' => $u->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
