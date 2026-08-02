<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::prefix('portal')->name('portal.')->middleware('peran:media,superadmin')->group(function () {
    Route::get('/', fn () => Inertia::render('portal/Dashboard'))->name('dashboard');
});
