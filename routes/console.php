<?php

use App\Support\Waktu;
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

// Seminggu terakhir ditulis ulang tiap 10 menit supaya dashboard tidak basi.
//
// Dulu hanya `--hari=1`, dan itu meleset untuk kasus yang justru paling sering.
// Artikel yang baru diunduh siang ini bisa terbit tiga hari lalu, dan agregasi
// memakai tanggal terbit, jadi barisnya jatuh ke tanggal yang tidak ikut
// dihitung ulang. Akibatnya media itu tampak nol di Peringkat Media sampai
// penyisiran 90 hari berjalan pukul 03:10 keesokan harinya. Detikcom dan
// Portal.id sempat kosong seharian karena ini padahal artikelnya sudah masuk
// dan sudah berlabel.
//
// Tujuh hari, sepadan dengan rentang bawaan halaman eksekutif. Sekitar dua
// ratus upsert satu baris tiap sepuluh menit, dan itu jauh lebih murah
// daripada pimpinan membaca angka nol yang tidak benar.
Schedule::command('hitung:ringkasan-harian --hari=7')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Sekali sehari rentangnya diperlebar, untuk dua hal sekaligus: koreksi label
// yang baru dilakukan admin belakangan, dan artikel lama yang baru terunduh.
//
// Sembilan puluh hari, bukan tujuh. Sejak agregasi memakai tanggal terbit,
// artikel yang diunduh hari ini bisa jatuh ke tanggal dua bulan lalu, dan
// jendela seminggu tidak akan pernah menyentuhnya. Sembilan puluh upsert satu
// baris sekali sehari jauh lebih murah daripada grafik yang diam-diam kurang
// menghitung. Feed yang membawa artikel lebih tua dari itu perlu
// `hitung:ringkasan-harian --hari=N` sekali secara manual.
Schedule::command('hitung:ringkasan-harian --hari=90')
    ->dailyAt('03:10')
    ->withoutOverlapping();

// Narasi eksekutif. Satu panggilan Gemini per periode, dan sidik bahan di
// tabelnya membatalkan panggilan itu kalau tidak ada berita baru sejak generasi
// terakhir, jadi jam-jam malam tidak membakar kuota.
//
// Dua irama karena biayanya tidak sama. Rentang hari ini dan minggu kalender
// paling sering dibuka dan paling cepat basi. Rentang bulan kalender dan tiga
// bulan berjalan berubah lebih pelan, dan promptnya paling panjang, jadi sekali
// sehari sudah cukup. Bulan sebelumnya ikut diperiksa agar laporan yang baru
// tersedia setelah pergantian bulan tetap memiliki ringkasan final. Sidik bahan
// mencegah panggilan Gemini baru kalau datanya tidak berubah.
Schedule::command('narasi:eksekutif --periode=today --periode=7d')
    ->hourly()
    ->withoutOverlapping();

Schedule::command(
    'narasi:eksekutif --periode=30d --periode=90d --bulan='.
    now(Waktu::ZONA)->subMonthNoOverflow()->format('Y-m')
)
    ->dailyAt('04:10')
    // Aplikasi sengaja berjalan di UTC, tetapi jadwal laporan dibaca manusia
    // Kendari. Tanpa zona eksplisit, 04.10 di sini sebenarnya menjadi 12.10
    // WITA dan keterangan jadwal pada halaman admin akan menyesatkan.
    ->timezone(Waktu::ZONA)
    ->withoutOverlapping();

// Log crawl tumbuh cepat dan nilainya menurun tajam setelah beberapa minggu.
Schedule::command('log:bersihkan')->dailyAt('02:30');

// Alert. Pembatas pengiriman berulang ada di aturannya sendiri
// (jeda_minimal_jam), jadi frekuensi di sini menentukan seberapa cepat
// peringatan sampai, bukan seberapa sering grup Telegram dibanjiri.
Schedule::command('alert:periksa')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

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

// Cadangan basis data, tiap Senin pukul 03.00 WITA.
//
// Zona eksplisit, bukan bawaan UTC. Tanpa itu jadwalnya jatuh pukul 11.00 WITA,
// yaitu jam kerja, dan pg_dump mengunci baca seluruh tabel selama ia berjalan.
//
// Satu berkas per minggu, dan aturan itu ada di CadanganDatabase, bukan di
// jadwal ini. Admin yang menekan tombol manual hari Rabu menimpa hasil Senin,
// dan jadwal Senin berikutnya menimpa berkas manual minggu sebelumnya hanya
// kalau keduanya jatuh di minggu kalender yang sama.
//
// runInBackground() sengaja tidak dipakai. Perintah ini menulis satu berkas
// besar dan kode keluarnya adalah satu-satunya tanda bahwa cadangan mingguan
// gagal, jadi ia harus tercatat di log penjadwal apa adanya.
Schedule::command('cadangan:buat')
    ->weeklyOn(1, '03:00')
    ->timezone(Waktu::ZONA)
    ->withoutOverlapping();
