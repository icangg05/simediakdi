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

// Hari ini ditulis ulang tiap 10 menit supaya dashboard tidak basi.
Schedule::command('hitung:ringkasan-harian --hari=1')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Sekali sehari rentangnya diperlebar untuk menangkap koreksi label yang baru
// dilakukan admin belakangan.
Schedule::command('hitung:ringkasan-harian --hari=7')
    ->dailyAt('03:10')
    ->withoutOverlapping();

// Isu hangat. Lebih berat daripada ringkasan harian karena menghitung n-gram
// dari seluruh artikel periode itu, jadi sejam sekali sudah cukup.
Schedule::command('hitung:kata-kunci --hari=1')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('hitung:kata-kunci --hari=7 --granularitas=mingguan')
    ->dailyAt('03:40')
    ->withoutOverlapping();

// Log crawl tumbuh cepat dan nilainya menurun tajam setelah beberapa minggu.
Schedule::command('log:bersihkan')->dailyAt('02:30');

// Alert. Pembatas pengiriman berulang ada di aturannya sendiri
// (jeda_minimal_jam), jadi frekuensi di sini menentukan seberapa cepat
// peringatan sampai, bukan seberapa sering grup Telegram dibanjiri.
Schedule::command('alert:periksa')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Pagi hari sebelum jam kerja: peringatan tenggat kontrak berguna saat masih
// ada waktu menindaklanjutinya hari itu juga.
Schedule::command('kontrak:periksa-tenggat')->dailyAt('07:00');
