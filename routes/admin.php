<?php

use App\Http\Controllers\Admin\ArtikelController;
use App\Http\Controllers\Admin\AturanAlertController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EksporController;
use App\Http\Controllers\Admin\EntitasController;
use App\Http\Controllers\Admin\EvaluasiController;
use App\Http\Controllers\Admin\KonteksController;
use App\Http\Controllers\Admin\KontrakController;
use App\Http\Controllers\Admin\KoreksiLabelController;
use App\Http\Controllers\Admin\LogCrawlController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\PelabelanController;
use App\Http\Controllers\Admin\PengaturanController;
use App\Http\Controllers\Admin\PenggunaController;
use App\Http\Controllers\Admin\RelevanceDatasetLabelController;
use App\Http\Controllers\Admin\RelevanceLabController;
use App\Http\Controllers\Admin\RelevanceSnapshotController;
use App\Http\Controllers\Admin\RelevanceTrainingController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\SumberFeedController;
use App\Http\Controllers\Admin\VerifikasiPemuatanController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware('peran:superadmin,walikota')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('artikel', [ArtikelController::class, 'index'])->name('artikel.index');
    Route::get('artikel/{artikel}', [ArtikelController::class, 'show'])->name('artikel.show');
    Route::put('analisis/{analisis}', [KoreksiLabelController::class, 'update'])->name('analisis.update');

    Route::get('log-crawl', [LogCrawlController::class, 'index'])->name('log-crawl.index');

    // Antrean artikel yang skornya di antara dua ambang relevansi. Dipisah
    // dari pelabelan: keputusan di sini mengubah dashboard, keputusan di
    // pelabelan hanya mengubah penggarisnya.
    Route::get('review', [ReviewController::class, 'index'])->name('review.index');
    Route::post('review', [ReviewController::class, 'store'])->name('review.store');

    Route::get('pelabelan', [PelabelanController::class, 'index'])->name('pelabelan.index');
    Route::post('pelabelan', [PelabelanController::class, 'store'])->name('pelabelan.store');

    Route::get('evaluasi', EvaluasiController::class)->name('evaluasi.index');

    // Rate limit lebih ketat daripada rute biasa: satu ekspor bisa menyapu
    // puluhan ribu baris (dokumen 06 bagian 7).
    Route::get('ekspor/artikel', [EksporController::class, 'artikel'])
        ->middleware('throttle:10,1')
        ->name('ekspor.artikel');

    Route::post('kontrak/{kontrak}/cocokkan', [KontrakController::class, 'cocokkan'])->name('kontrak.cocokkan');
    Route::resource('kontrak', KontrakController::class);
    Route::resource('pengguna', PenggunaController::class)->except('show');

    // Antrean verifikasi laporan pemuatan dari portal media.
    Route::get('pemuatan', [VerifikasiPemuatanController::class, 'index'])->name('pemuatan.index');
    Route::put('pemuatan/{pemuatan}', [VerifikasiPemuatanController::class, 'update'])->name('pemuatan.update');
    // Bukti disimpan di luar public/, jadi satu-satunya jalan membacanya
    // adalah rute yang melewati middleware peran.
    Route::get('pemuatan/{pemuatan}/bukti', [VerifikasiPemuatanController::class, 'bukti'])->name('pemuatan.bukti');

    Route::post('alert/uji-telegram', [AturanAlertController::class, 'ujiTelegram'])->name('alert.uji-telegram');
    Route::post('alert/{alert}/uji', [AturanAlertController::class, 'uji'])->name('alert.uji');
    Route::resource('alert', AturanAlertController::class)->except('show');

    Route::post('entitas/{entitas}/gabungkan', [EntitasController::class, 'gabungkan'])->name('entitas.gabungkan');
    Route::resource('entitas', EntitasController::class)
        ->except('show')
        ->parameters(['entitas' => 'entitas']);

    Route::get('pengaturan', PengaturanController::class)->name('pengaturan');

    // Laboratorium Model Relevansi, tertutup untuk walikota termasuk baca.
    // Bukan karena isinya rahasia, melainkan karena setiap angka di dalamnya
    // angka setengah jadi: model kandidat yang gagal, presisi yang belum
    // memenuhi standar, dan label yang masih diperdebatkan. Angka setengah jadi
    // yang dibaca di luar konteksnya berubah menjadi kesimpulan, dan kesimpulan
    // itu tidak bisa ditarik kembali. Dokumen 06 bagian 2, dokumen 10 bagian 17.6.
    Route::middleware('peran:superadmin')->group(function () {
        Route::get('model-relevansi', RelevanceLabController::class)->name('model-relevansi');

        Route::post('model-relevansi/sampel/{sampel}/label', [RelevanceDatasetLabelController::class, 'store'])
            ->name('model-relevansi.label');
        Route::post('model-relevansi/sampel/{sampel}/lewati', [RelevanceDatasetLabelController::class, 'lewati'])
            ->name('model-relevansi.lewati');
        Route::post('model-relevansi/sampel/{sampel}/keluarkan', [RelevanceDatasetLabelController::class, 'keluarkan'])
            ->name('model-relevansi.keluarkan');

        Route::post('model-relevansi/snapshot', [RelevanceSnapshotController::class, 'store'])
            ->name('model-relevansi.snapshot.store');
        Route::post('model-relevansi/snapshot/{snapshot}/kunci', [RelevanceSnapshotController::class, 'kunci'])
            ->name('model-relevansi.snapshot.kunci');
        Route::delete('model-relevansi/snapshot/{snapshot}', [RelevanceSnapshotController::class, 'hapus'])
            ->name('model-relevansi.snapshot.hapus');

        Route::post('model-relevansi/pelatihan', [RelevanceTrainingController::class, 'store'])
            ->name('model-relevansi.pelatihan.store');
        Route::post('model-relevansi/pelatihan/{pelatihan}/batalkan', [RelevanceTrainingController::class, 'batalkan'])
            ->name('model-relevansi.pelatihan.batalkan');
    });

    Route::resource('media', MediaController::class)->except('show');
    Route::resource('sumber-feed', SumberFeedController::class)
        ->except('show')
        ->parameters(['sumber-feed' => 'sumberFeed']);
    // parameters() wajib: Laravel menunggalkan "konteks" menjadi "{kontek}",
    // lalu route model binding tidak cocok dengan argumen $konteks dan aturan
    // unique yang membaca route('konteks') selalu dapat null saat update.
    Route::resource('konteks', KonteksController::class)
        ->except('show')
        ->parameters(['konteks' => 'konteks']);
});
