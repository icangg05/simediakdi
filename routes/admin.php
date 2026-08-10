<?php

use App\Http\Controllers\Admin\AntreanAiController;
use App\Http\Controllers\Admin\ArtikelController;
use App\Http\Controllers\Admin\AturanAlertController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EksporController;
use App\Http\Controllers\Admin\KontrakController;
use App\Http\Controllers\Admin\KoreksiLabelController;
use App\Http\Controllers\Admin\LogCrawlController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\ModelRelevansiController;
use App\Http\Controllers\Admin\PengaturanAiController;
use App\Http\Controllers\Admin\PengaturanController;
use App\Http\Controllers\Admin\PenggunaController;
use App\Http\Controllers\Admin\SumberFeedController;
use App\Http\Controllers\Admin\VerifikasiPemuatanController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware('peran:superadmin,walikota')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Satu halaman untuk membaca dan bertindak. Klasifikasi dijalankan per
    // artikel lewat tombol, bukan di latar belakang, sampai alurnya terbukti
    // cukup stabil untuk dilepas.
    Route::get('artikel', [ArtikelController::class, 'index'])->name('artikel.index');

    // Pembuangan artikel. Didaftarkan sebelum `artikel/{artikel}` karena
    // `artikel/buang` akan tertangkap route model binding kalau urutannya
    // terbalik, dan Laravel menjawabnya 404 untuk artikel bernama "buang".
    //
    // Throttle-nya bukan soal beban server. Ini aksi yang tidak bisa
    // dibatalkan, dan tombol tanpa batas adalah tombol yang cepat atau lambat
    // ditekan dua kali.
    Route::delete('artikel/buang', [ArtikelController::class, 'hapus'])
        ->middleware('throttle:20,1,buang-artikel')
        ->name('artikel.buang');
    Route::delete('artikel/buang-semua', [ArtikelController::class, 'hapusSemua'])
        ->middleware('throttle:3,1,buang-artikel-semua')
        ->name('artikel.buang-semua');

    Route::get('artikel/{artikel}', [ArtikelController::class, 'show'])->name('artikel.show');
    /*
     * Ketiga rute tidak memakai throttle waktu per pengguna. Rotasi Gemini
     * memilih kunci yang sudah menganggur 15 detik untuk setiap aksi manual;
     * pengguna hanya diminta menunggu bila seluruh kunci masih dalam cooldown.
     */
    Route::post('artikel/{artikel}/klasifikasi', [ArtikelController::class, 'klasifikasi'])
        ->name('artikel.klasifikasi');
    Route::post('artikel/{artikel}/relevansi', [ArtikelController::class, 'relevansi'])
        ->name('artikel.relevansi');
    Route::post('artikel/{artikel}/reset', [ArtikelController::class, 'reset'])
        ->name('artikel.reset');
    Route::put('analisis/{analisis}', [KoreksiLabelController::class, 'update'])->name('analisis.update');

    // Pemantauan antrean klasifikasi otomatis. Halamannya menarik dirinya
    // sendiri tiap beberapa detik, jadi rutenya harus tetap murah: seluruh
    // isinya hitungan agregat di atas tabel yang sudah terindeks.
    //
    // Hanya GET. Rute POST `antrean-ai/isi` dihapus bersama tombolnya: ia
    // menunjuk ke method yang tidak pernah ada di controllernya dan selalu
    // menjawab 500. Penyisirannya sendiri tetap berjalan lewat penjadwal, dan
    // artikel baru sudah mengantre sendiri begitu isinya selesai diekstrak.
    Route::get('antrean-ai', [AntreanAiController::class, 'index'])->name('antrean-ai.index');

    Route::get('log-crawl', [LogCrawlController::class, 'index'])->name('log-crawl.index');
    // Satu crawl penuh menarik 28 feed dari 28 server milik orang lain. Dua
    // kali semenit sudah lebih dari cukup, dan menahan admin yang menekan
    // tombolnya berulang kali dari membuat kita terlihat seperti penyerang di
    // log mereka.
    Route::post('log-crawl/jalankan', [LogCrawlController::class, 'crawl'])
        ->middleware('throttle:2,1,crawl-manual')
        ->name('log-crawl.jalankan');

    // Rate limit lebih ketat daripada rute biasa: satu ekspor bisa menyapu
    // puluhan ribu baris (dokumen 06 bagian 7).
    Route::get('ekspor/artikel', [EksporController::class, 'artikel'])
        ->middleware('throttle:10,1,ekspor')
        ->name('ekspor.artikel');

    Route::post('kontrak/{kontrak}/cocokkan', [KontrakController::class, 'cocokkan'])->name('kontrak.cocokkan');
    Route::resource('kontrak', KontrakController::class);
    Route::resource('pengguna', PenggunaController::class)->except('show');

    // Antrean verifikasi laporan pemuatan dari portal media.
    Route::get('pemuatan', [VerifikasiPemuatanController::class, 'index'])->name('pemuatan.index');
    Route::put('pemuatan/{pemuatan}', [VerifikasiPemuatanController::class, 'update'])->name('pemuatan.update');
    // Bukti disimpan di luar public/, jadi satu-satunya jalan membacanya
    // adalah rute yang melewati middleware peran.
    Route::get('pemuatan/{pemuatan}/bukti', [VerifikasiPemuatanController::class, 'bukti'])->name('pemuatan.bukti');

    Route::post('alert/uji-telegram', [AturanAlertController::class, 'ujiTelegram'])->name('alert.uji-telegram');
    Route::post('alert/{alert}/uji', [AturanAlertController::class, 'uji'])->name('alert.uji');
    Route::resource('alert', AturanAlertController::class)->except('show');

    Route::get('pengaturan', PengaturanController::class)->name('pengaturan');

    // Penyuntingan pengaturan Gemini terbatas superadmin. Walikota membaca
    // halaman Pengaturan, tidak mengubah prompt yang menentukan seluruh angka
    // di dashboardnya sendiri.
    Route::middleware('peran:superadmin')->group(function () {
        Route::put('pengaturan/ai', [PengaturanAiController::class, 'update'])->name('pengaturan.ai');
        Route::post('pengaturan/kunci', [PengaturanAiController::class, 'simpanKunci'])->name('pengaturan.kunci.simpan');
        Route::put('pengaturan/kunci/{kunci}', [PengaturanAiController::class, 'ubahKunci'])->name('pengaturan.kunci.ubah');
        Route::delete('pengaturan/kunci/{kunci}', [PengaturanAiController::class, 'hapusKunci'])->name('pengaturan.kunci.hapus');
        // Throttle ketat, dan bukan karena beban server. Satu ketukan adalah
        // satu permintaan yang dihitung penuh oleh Google, jadi tombol yang
        // ditekan berulang kali memakan kuota penilaian artikel.
        Route::post('pengaturan/kunci/{kunci}/uji', [PengaturanAiController::class, 'ujiKunci'])
            ->middleware('throttle:10,1,uji-kunci')
            ->name('pengaturan.kunci.uji');

        // Model Relevansi. Terbatas superadmin, sama seperti pengaturan Gemini
        // di atasnya dan karena alasan yang sama: yang ditentukan dari halaman
        // ini bukan angka yang dibaca, melainkan model mana yang berlaku.
        Route::prefix('model-relevansi')->name('model-relevansi.')->group(function () {
            Route::get('/', [ModelRelevansiController::class, 'index'])->name('index');

            // Satu snapshot menyalin ratusan artikel beserta teksnya ke tabel
            // baru dalam satu transaksi. Bukan beban yang berat, tetapi juga
            // bukan tombol yang perlu bisa ditekan berkali-kali semenit.
            Route::post('snapshot', [ModelRelevansiController::class, 'simpanSnapshot'])
                ->middleware('throttle:10,1,model-relevansi')
                ->name('snapshot.simpan');
            Route::delete('snapshot/{snapshot}', [ModelRelevansiController::class, 'hapusSnapshot'])
                ->name('snapshot.hapus');

            Route::post('pelatihan', [ModelRelevansiController::class, 'simpanPelatihan'])
                ->middleware('throttle:10,1,model-relevansi')
                ->name('pelatihan.simpan');
            Route::post('pelatihan/{pelatihan}/batal', [ModelRelevansiController::class, 'batalPelatihan'])
                ->name('pelatihan.batal');
            Route::post('pelatihan/{pelatihan}/aktifkan', [ModelRelevansiController::class, 'aktifkanPelatihan'])
                ->name('pelatihan.aktifkan');
            Route::delete('pelatihan/{pelatihan}', [ModelRelevansiController::class, 'hapusPelatihan'])
                ->name('pelatihan.hapus');
            Route::get('pelatihan/{pelatihan}/evaluasi', [ModelRelevansiController::class, 'unduhEvaluasi'])
                ->name('pelatihan.evaluasi');

            Route::post('uji', [ModelRelevansiController::class, 'uji'])
                ->middleware('throttle:30,1,model-relevansi-uji')
                ->name('uji');
        });
    });

    // Crawl satu media. Batasnya lebih longgar daripada crawl penuh karena
    // yang ditarik hanya sumber milik satu media, bukan 28 server sekaligus.
    Route::post('media/{media}/crawl', [MediaController::class, 'crawl'])
        ->middleware('throttle:10,1,crawl-media')
        ->name('media.crawl');
    Route::resource('media', MediaController::class)->except('show');
    Route::resource('sumber-feed', SumberFeedController::class)
        ->except('show')
        ->parameters(['sumber-feed' => 'sumberFeed']);
});
