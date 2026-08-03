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
use App\Http\Controllers\Admin\SumberFeedController;
use App\Http\Controllers\Admin\VerifikasiPemuatanController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware('peran:superadmin,walikota')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('artikel', [ArtikelController::class, 'index'])->name('artikel.index');
    Route::get('artikel/{artikel}', [ArtikelController::class, 'show'])->name('artikel.show');
    Route::put('analisis/{analisis}', [KoreksiLabelController::class, 'update'])->name('analisis.update');

    Route::get('log-crawl', [LogCrawlController::class, 'index'])->name('log-crawl.index');

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
