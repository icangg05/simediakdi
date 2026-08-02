<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PastikanPeran;
use App\Http\Middleware\TolakSemuaTulisan;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
