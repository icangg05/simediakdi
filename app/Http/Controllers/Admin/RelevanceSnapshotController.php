<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SnapshotDatasetRelevansi;
use App\Services\Relevance\RelevanceDatasetExporter;
use App\Services\Relevance\RelevanceSnapshotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use RuntimeException;

class RelevanceSnapshotController extends Controller
{
    public function store(Request $request, RelevanceSnapshotService $service): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:150'],
            'deskripsi' => ['nullable', 'string', 'max:1000'],
            'strategi_sampling' => ['required', Rule::in(['balanced', 'natural_distribution', 'balanced_with_hard_cases', 'custom'])],
            'random_seed' => ['required', 'integer', 'min:0'],
            'persen_train' => ['required', 'integer', 'min:1', 'max:98'],
            'persen_validation' => ['required', 'integer', 'min:1', 'max:98'],
            'persen_test' => ['required', 'integer', 'min:1', 'max:98'],
        ]);

        try {
            $snapshot = $service->buat($data, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', "Snapshot {$snapshot->nama} {$snapshot->versi} dibuat sebagai draft. Periksa laporan kebocorannya sebelum dikunci.");
    }

    /**
     * Mengunci snapshot. Setelah ini susunannya tidak bisa berubah lagi.
     *
     * Kebocoran diperiksa ulang di service, bukan hanya dipercaya dari laporan
     * yang tampil di layar. Laporan itu dihitung saat halaman dibuka, dan
     * dataset bisa berubah di antara membaca laporan dan menekan tombol.
     */
    public function kunci(
        SnapshotDatasetRelevansi $snapshot,
        Request $request,
        RelevanceSnapshotService $service,
    ): RedirectResponse {
        try {
            $service->kunci($snapshot, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('galat', $e->getMessage());
        }

        return back()->with('sukses', 'Snapshot dikunci. Anggota test set ikut terkunci dan hanya bisa diubah dengan alasan.');
    }

    /**
     * Menghapus snapshot yang masih draft.
     *
     * Hanya draft. Snapshot terkunci adalah catatan tentang data apa yang
     * dipakai satu eksperimen, dan menghapusnya berarti membuang satu-satunya
     * cara menjelaskan angka evaluasi yang sudah terlanjur dilaporkan. Dokumen
     * 10 bagian 15.4 menyebutnya sebagai pengecualian yang tidak boleh
     * dilanggar retensi otomatis, dan alasan yang sama berlaku untuk tombol.
     *
     * Kehilangannya kecil: draft yang dihapus bisa dibuat ulang persis sama
     * dari nama, benih, dan porsi yang sama, selama datasetnya belum berubah.
     */
    public function hapus(SnapshotDatasetRelevansi $snapshot): RedirectResponse
    {
        if ($snapshot->terkunci()) {
            return back()->with('galat', 'Snapshot yang sudah dikunci tidak bisa dihapus. Buat snapshot baru kalau susunannya perlu diubah.');
        }

        // Foreign key-nya memang menolak, tetapi galat SQL mentah tidak
        // memberi tahu admin apa yang harus dikerjakannya.
        $dipakai = DB::table('pelatihan_model_relevansi')
            ->where('snapshot_dataset_relevansi_id', $snapshot->id)
            ->exists();

        if ($dipakai) {
            return back()->with('galat', 'Snapshot ini sudah dipakai satu pelatihan, jadi tidak bisa dihapus.');
        }

        $nama = "{$snapshot->nama} {$snapshot->versi}";

        // Dataset hasil ekspor ikut dibuang. Item snapshot memang terhapus
        // lewat cascade foreign key, tetapi berkas JSONL di disk tidak dijaga
        // database mana pun, dan ia memuat judul serta isi artikel selengkapnya.
        // Meninggalkannya bukan cuma soal disk: itu salinan isi berita yang
        // tidak lagi ditunjuk apa pun dan tidak akan pernah ditinjau siapa pun.
        $direktori = app(RelevanceDatasetExporter::class)->direktori($snapshot);
        $sisa = null;

        if (File::isDirectory($direktori)) {
            File::deleteDirectory($direktori);

            // Diperiksa, bukan dipercaya. Berkasnya ditulis proses ini tetapi
            // pernah ditulis proses lain dengan pemilik berbeda, dan
            // `deleteDirectory` mengembalikan false tanpa melempar apa pun.
            $sisa = File::isDirectory($direktori) ? $direktori : null;
        }

        $snapshot->delete();

        if ($sisa !== null) {
            return back()->with('galat', "Snapshot {$nama} dihapus, tetapi dataset ekspornya di {$sisa} tidak bisa dibuang. Periksa kepemilikan berkasnya lalu hapus manual.");
        }

        return back()->with('sukses', "Snapshot {$nama} dihapus beserta dataset ekspornya.");
    }
}
