<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Seluruh panggilan HTTP keluar memakai IPv4.
         *
         * Jaringan bawaan Docker tidak punya rute IPv6, sedangkan DNS di dalam
         * container tetap menjawab AAAA. api.telegram.org menjawab keduanya,
         * jadi curl kadang mencoba alamat IPv6 lebih dulu, gagal menyambung,
         * dan permintaannya berakhir sebagai "cURL error 7" setelah beberapa
         * detik. Gejalanya berselang-seling, karena percobaan berikutnya bisa
         * saja jatuh ke IPv4 dan berhasil, dan itu jenis kerusakan yang paling
         * lama dikira salah kredensial.
         *
         * Ditaruh di sini, bukan di PengirimTelegram, karena sebabnya jaringan
         * container, bukan Telegram. Gemini dan layanan lain di luar container
         * kena hal yang sama. Layanan internal hanya punya alamat IPv4, jadi
         * baris ini tidak mengubah apa pun bagi mereka.
         */
        Http::globalOptions([
            'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
        ]);

        /*
         * Batas pengiriman Telegram ke satu grup atau kanal, sekitar 20 pesan
         * per menit.
         *
         * Alert berita negatif mengirim satu pesan per artikel, dan satu putaran
         * crawl bisa menghasilkan puluhan berita negatif sekaligus. Tanpa
         * pembatas ini Telegram menolak sisanya dengan 429, dan yang tertolak
         * adalah kabar yang paling perlu sampai.
         *
         * Angkanya sengaja di bawah batas resmi. Tombol Kirim uji dan alert
         * berkala memakai jatah yang sama, dan batas yang dipepetkan persis
         * akan terlampaui oleh satu pesan manual yang kebetulan berbarengan.
         */
        RateLimiter::for('telegram', fn () => Limit::perMinute(18));
    }
}
