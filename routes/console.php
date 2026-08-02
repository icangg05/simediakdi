<?php

use Illuminate\Support\Facades\Schedule;

// Jadwal lengkap ada di dokumen 02 bagian 7. Yang belum terdaftar di sini
// menunggu command-nya dibuat di sprint 3 sampai 5.

// withoutOverlapping() wajib pada seluruh perintah crawl dan agregasi: satu
// sumber yang lambat tidak boleh memicu dua proses yang menulis baris sama.
Schedule::command('crawl:feeds')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('crawl:google-news')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Log crawl tumbuh cepat dan nilainya menurun tajam setelah beberapa minggu.
Schedule::command('log:bersihkan')->dailyAt('02:30');
