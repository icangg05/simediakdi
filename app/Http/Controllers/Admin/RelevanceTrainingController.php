<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PantauPelatihanRelevansi;
use App\Jobs\PrediksiSampelRelevansi;
use App\Models\PelatihanModelRelevansi;
use App\Models\SnapshotDatasetRelevansi;
use App\Models\VersiModelRelevansi;
use App\Services\Relevance\RelevanceTrainingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Throwable;

class RelevanceTrainingController extends Controller
{
    public function store(Request $request, RelevanceTrainingService $service): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:150'],
            'snapshot_dataset_relevansi_id' => ['required', 'integer', 'exists:snapshot_dataset_relevansi,id'],
            'epoch' => ['required', 'numeric', 'min:1', 'max:20'],
            'batch_size' => ['required', 'integer', 'min:1', 'max:32'],
            'gradient_accumulation' => ['required', 'integer', 'min:1', 'max:32'],
            'learning_rate' => ['required', 'numeric', 'min:0.000001', 'max:0.01'],
            'max_length' => ['required', 'integer', 'min:64', 'max:512'],
            'class_weighting' => ['required', 'boolean'],
            'random_seed' => ['required', 'integer', 'min:0'],
        ]);

        $snapshot = SnapshotDatasetRelevansi::findOrFail($data['snapshot_dataset_relevansi_id']);

        try {
            $run = $service->mulai($snapshot, $data, $request->user());
        } catch (Throwable $e) {
            return back()->with('galat', $e->getMessage());
        }

        PantauPelatihanRelevansi::dispatch($run->id);

        return back()->with('sukses', "Pelatihan {$run->nama} dimulai. Kemajuannya menyegarkan sendiri di halaman ini.");
    }

    public function batalkan(
        PelatihanModelRelevansi $pelatihan,
        RelevanceTrainingService $service,
    ): RedirectResponse {
        if ($pelatihan->selesai()) {
            return back()->with('galat', 'Pelatihan ini sudah berhenti.');
        }

        $service->batalkan($pelatihan);

        return back()->with('sukses', 'Permintaan pembatalan dikirim. Pelatihan berhenti setelah langkah yang sedang jalan selesai.');
    }

    /**
     * Menjalankan prediksi atas seluruh dataset memakai model terpilih.
     *
     * Ini yang menyalakan active learning: antrean pelabelan bisa menunjuk
     * tempat model sendiri ragu, alih-alih hanya menebak dari kata kunci.
     *
     * Prediksi memakai model kandidat pun boleh, dan itu disengaja. Yang
     * dilarang gerbang mutu adalah meneruskan artikel ke sentimen, bukan
     * memakai model untuk memilih artikel mana yang layak dilabeli manusia.
     */
    public function prediksi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['nullable', 'string', 'exists:versi_model_relevansi,versi'],
        ]);

        if (VersiModelRelevansi::count() === 0) {
            return back()->with('galat', 'Belum ada model relevansi. Latih satu lebih dulu.');
        }

        PrediksiSampelRelevansi::dispatch($data['model'] ?? null);

        return back()->with('sukses', 'Prediksi dataset dimulai di latar. Sekitar setengah jam untuk 4.000 sampel, dan hasilnya muncul sebagai kolom Prediksi model di tab Dataset.');
    }

    /**
     * Menghapus riwayat pelatihan yang gagal atau dibatalkan.
     *
     * Hanya keduanya. Pelatihan yang berhasil punya model kandidat yang
     * menunjuk kepadanya, dan menghapusnya meninggalkan model tanpa asal usul:
     * tidak ada lagi cara mengetahui snapshot dan konfigurasi apa yang
     * menghasilkannya.
     *
     * Ini melunakkan aturan yang saya tulis sendiri, bahwa riwayat kegagalan
     * tidak pernah dihapus. Alasannya tetap berlaku untuk kegagalan
     * eksperimen, yaitu konfigurasi yang dicoba lalu tidak berhasil, karena
     * itulah yang mencegah orang berikutnya mengulanginya. Yang tidak
     * mengajarkan apa pun adalah kegagalan lingkungan: dependensi yang belum
     * terpasang, direktori yang belum terhubung. Membedakan keduanya butuh
     * penilaian manusia, jadi tombolnya disediakan dan keputusannya diserahkan.
     */
    public function hapus(PelatihanModelRelevansi $pelatihan): RedirectResponse
    {
        if (! in_array($pelatihan->status, ['gagal', 'dibatalkan'], strict: true)) {
            return back()->with('galat', 'Hanya pelatihan yang gagal atau dibatalkan yang bisa dihapus dari riwayat.');
        }

        // Artefak setengah jadi ikut dibuang. Direktori 1,3 GB milik pelatihan
        // yang tidak pernah selesai tidak berguna bagi siapa pun, dan sepuluh
        // di antaranya memenuhi disk tanpa ada yang menyadarinya.
        $artefak = $pelatihan->artifact_path;
        $sisa = null;

        if ($artefak && str_starts_with($artefak, rtrim(config('relevance.artefak_path'), '/'))) {
            File::deleteDirectory($artefak);

            // Diperiksa, bukan dipercaya. Artefak ditulis proses lain, dan
            // `deleteDirectory` mengembalikan false tanpa melempar apa pun saat
            // izinnya kurang. Menghapus barisnya sambil membiarkan direktorinya
            // berarti 1,3 GB tertinggal tanpa satu pun catatan yang menunjuknya.
            $sisa = File::isDirectory($artefak) ? $artefak : null;
        }

        $nama = $pelatihan->nama;
        $pelatihan->delete();

        if ($sisa !== null) {
            return back()->with('galat', "Riwayat pelatihan {$nama} dihapus, tetapi artefaknya di {$sisa} tidak bisa dibuang. Periksa kepemilikan berkasnya lalu hapus manual.");
        }

        return back()->with('sukses', "Riwayat pelatihan {$nama} dihapus beserta artefaknya.");
    }
}
