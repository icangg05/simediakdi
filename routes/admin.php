<?php

use App\Http\Controllers\Admin\ArtikelController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EvaluasiController;
use App\Http\Controllers\Admin\KonteksController;
use App\Http\Controllers\Admin\KoreksiLabelController;
use App\Http\Controllers\Admin\LogCrawlController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\PelabelanController;
use App\Http\Controllers\Admin\SumberFeedController;
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
