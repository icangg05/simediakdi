<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::prefix('eksekutif')->name('eksekutif.')->middleware('peran:walikota,superadmin')->group(function () {
    Route::get('/', fn () => Inertia::render('eksekutif/Dashboard'))->name('dashboard');
});
