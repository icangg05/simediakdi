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
