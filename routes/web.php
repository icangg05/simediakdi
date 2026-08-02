<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Tidak ada halaman publik: setiap pengguna sistem ini sudah punya akun.
Route::get('/', function () {
    return redirect(Auth::check() ? Auth::user()->peran->beranda() : route('login'));
})->name('home');

// Satu aplikasi, tiga grup route. Peran menentukan beranda dan akses.
Route::get('dashboard', function () {
    return redirect(Auth::user()->peran->beranda());
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    require __DIR__.'/admin.php';
    require __DIR__.'/eksekutif.php';
    require __DIR__.'/portal.php';
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
