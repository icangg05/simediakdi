<?php

use App\Jobs\Executive\GenerateExecutiveTopics;
use Illuminate\Support\Facades\Schedule;

// Jadwal lengkap ada di dokumen 02 bagian 7. Yang belum terdaftar di sini
// menunggu command-nya dibuat di sprint 3 sampai 5.

// withoutOverlapping() wajib pada seluruh perintah crawl dan agregasi: satu
// sumber yang lambat tidak boleh memicu dua proses yang menulis baris sama.
// Tiga jam sekali. Media daerah di Kendari menerbitkan beberapa berita per
// hari, bukan per menit, jadi menyisir tiap 15 menit hanya menghasilkan log
// penuh baris "0 baru" dan permintaan HTTP yang sia-sia ke 28 portal. Kalau
// ada kebutuhan mendesak, tombol Crawl sekarang di halaman Log crawl menarik
// seluruh sumber seketika tanpa menunggu jadwal.
Schedule::command('crawl:feeds')
    ->everyThreeHours()
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

// Antrean klasifikasi Gemini, dua irama untuk dua pekerjaan yang berbeda
// biayanya. Pengisian menyisir seluruh tabel artikel dengan tiga kueri
// whereHas, jadi sejam sekali. Pelepasan hanya membaca tabel antrean yang sudah
// terindeks, jadi tiap menit supaya worker tidak pernah menganggur menunggu
// jadwal berikutnya sementara kuota hari itu terbuang.
Schedule::command('gemini:antre --isi')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('gemini:antre')
    ->everyMinute()
    ->withoutOverlapping();

// Interpretasi eksekutif berjalan di belakang layar. Fingerprint di service
// mencegah panggilan Gemini bila populasi artikelnya belum berubah.
foreach (['today', '7d', '30d', '90d'] as $index => $period) {
    $minute = $index * 5;
    Schedule::job(new GenerateExecutiveTopics($period))
        ->cron("{$minute},".($minute + 30).' * * * *')
        ->withoutOverlapping();
}
