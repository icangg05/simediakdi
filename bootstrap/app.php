<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PastikanPeran;
use App\Http\Middleware\TolakSemuaTulisan;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Di produksi TLS diputus di nginx milik host, lalu permintaan
        // diteruskan sebagai http biasa ke Caddy dan aplikasi. Tanpa baris ini
        // Laravel membaca skema dari soketnya sendiri, menyimpulkan "http", dan
        // menuliskan seluruh URL Ziggy serta action form sebagai http://.
        // Halaman yang dibuka lewat https lalu menolak mengirimnya sendiri:
        // peramban memblokir permintaan Inertia sebagai konten campuran, dan
        // gejalanya adalah tombol yang diam tanpa satu pun galat di log server.
        //
        // `at: '*'` karena alamat Caddy di jaringan Docker berganti setiap kali
        // container dibuat ulang, jadi tidak ada IP tetap yang bisa didaftar.
        // Ini aman selama app tidak menerbitkan port ke host, dan memang tidak:
        // docker-compose.yml hanya memberinya `expose`, sehingga satu-satunya
        // jalan masuk adalah nginx lalu Caddy.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Global, bukan per route: route baru yang lupa dilindungi tetap
            // tertutup untuk peran walikota.
            TolakSemuaTulisan::class,
        ]);

        $middleware->alias([
            'peran' => PastikanPeran::class,
        ]);

        // Penolakan tulisan harus jatuh sebelum penghitung throttle, bukan
        // sesudahnya.
        //
        // ThrottleRequests ada di daftar prioritas bawaan Laravel, jadi ia
        // berjalan lebih dulu daripada middleware yang sekadar ditempel ke grup
        // web. Akibatnya permintaan tulis dari peran walikota, yang seharusnya
        // ditolak mentah-mentah, tetap menghabiskan jatah throttle pemiliknya
        // dan menerima 429 alih-alih 403. Pesan yang salah, dan jatah yang
        // terpakai untuk permintaan yang tidak pernah dikerjakan.
        $middleware->prependToPriorityList(ThrottleRequests::class, TolakSemuaTulisan::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
