<?php

use App\Http\Controllers\Eksekutif\ArsipBeritaController;
use App\Http\Controllers\Eksekutif\ArtikelController;
use App\Http\Controllers\Eksekutif\DashboardController;
use App\Http\Controllers\Eksekutif\LaporanController;
use App\Http\Controllers\Eksekutif\PeringkatMediaController;
use App\Http\Controllers\Eksekutif\SentimenController;
use Illuminate\Support\Facades\Route;

Route::prefix('eksekutif')->name('eksekutif.')->middleware('peran:walikota,superadmin')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('sentimen', SentimenController::class)->name('sentimen');
    Route::get('media', PeringkatMediaController::class)->name('media');
    Route::get('berita', ArsipBeritaController::class)->name('berita');
    Route::get('artikel/{artikel}', ArtikelController::class)->whereNumber('artikel')->name('artikel');
    Route::get('laporan', LaporanController::class)->name('laporan');
});
