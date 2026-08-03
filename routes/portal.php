<?php

use App\Http\Controllers\Portal\BeritaController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\KontrakController;
use App\Http\Controllers\Portal\LaporController;
use Illuminate\Support\Facades\Route;

Route::prefix('portal')->name('portal.')->middleware('peran:media,superadmin')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('berita', BeritaController::class)->name('berita');
    Route::get('kontrak', KontrakController::class)->name('kontrak');

    Route::get('lapor', [LaporController::class, 'index'])->name('lapor');
    // Setiap pemeriksaan mengunduh halaman media lain. Dibatasi lebih ketat
    // daripada rute biasa supaya portal tidak bisa dipakai membanjiri situs
    // orang lain lewat satu akun media yang bocor.
    Route::post('lapor/periksa', [LaporController::class, 'periksa'])
        ->middleware('throttle:20,1')
        ->name('lapor.periksa');
    Route::post('lapor', [LaporController::class, 'store'])->name('lapor.store');
});
