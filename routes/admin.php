<?php

use App\Http\Controllers\Admin\ArtikelController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LogCrawlController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\SumberFeedController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware('peran:superadmin,walikota')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('artikel', [ArtikelController::class, 'index'])->name('artikel.index');
    Route::get('log-crawl', [LogCrawlController::class, 'index'])->name('log-crawl.index');

    Route::resource('media', MediaController::class)->except('show');
    Route::resource('sumber-feed', SumberFeedController::class)
        ->except('show')
        ->parameters(['sumber-feed' => 'sumberFeed']);
});
