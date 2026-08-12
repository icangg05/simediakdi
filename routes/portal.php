<?php

use App\Http\Controllers\Portal\BeritaController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\LaporController;
use Illuminate\Support\Facades\Route;

Route::prefix('portal')->name('portal.')->middleware('peran:media,superadmin')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('berita', BeritaController::class)->name('berita');

    Route::get('lapor', [LaporController::class, 'index'])->name('lapor');
    // Setiap pemeriksaan mengunduh halaman media lain. Dibatasi lebih ketat
    // daripada rute biasa supaya portal tidak bisa dipakai membanjiri situs
    // orang lain lewat satu akun media yang bocor.
    Route::post('lapor/periksa', [LaporController::class, 'periksa'])
        ->middleware('throttle:20,1')
        ->name('lapor.periksa');
    Route::post('lapor', [LaporController::class, 'store'])->name('lapor.store');
    // Mencabut satu kiriman sendiri. Batas siapa boleh mencabut apa dijaga
    // PembuangArtikel::buangKirimanPortal(), bukan di sini, karena penghapusan
    // artikel hanya punya satu pintu di seluruh aplikasi.
    Route::delete('lapor/{artikel}', [LaporController::class, 'destroy'])->name('lapor.destroy');
});
