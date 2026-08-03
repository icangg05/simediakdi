<?php

use App\Http\Controllers\Eksekutif\ArsipBeritaController;
use App\Http\Controllers\Eksekutif\DashboardController;
use App\Http\Controllers\Eksekutif\IsuController;
use App\Http\Controllers\Eksekutif\PeringkatMediaController;
use App\Http\Controllers\Eksekutif\SentimenController;
use Illuminate\Support\Facades\Route;

Route::prefix('eksekutif')->name('eksekutif.')->middleware('peran:walikota,superadmin')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('sentimen', SentimenController::class)->name('sentimen');
    Route::get('isu', IsuController::class)->name('isu');
    Route::get('media', PeringkatMediaController::class)->name('media');
    Route::get('berita', ArsipBeritaController::class)->name('berita');
});
