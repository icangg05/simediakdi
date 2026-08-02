<?php

namespace App\Http\Middleware;

use App\Enums\PeranPengguna;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pakai sebagai `peran:superadmin` atau `peran:superadmin,walikota`.
 */
class PastikanPeran
{
    public function handle(Request $request, Closure $next, string ...$peran): Response
    {
        $user = $request->user();

        abort_unless($user && $user->aktif, 403);

        $diizinkan = array_map(
            fn (string $p) => PeranPengguna::from($p),
            $peran
        );

        abort_unless(in_array($user->peran, $diizinkan, strict: true), 403);

        return $next($request);
    }
}
