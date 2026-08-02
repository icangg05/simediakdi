<?php

namespace App\Http\Middleware;

use App\Enums\PeranPengguna;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sabuk pengaman peran walikota: hanya boleh membaca.
 *
 * Dipasang global, bukan per route, supaya route baru yang lupa diberi
 * pelindung tetap tertutup. Pengecualian dibatasi pada rute yang memang harus
 * bisa ditulis siapa pun yang login: logout dan pengaturan akun sendiri.
 */
class TolakSemuaTulisan
{
    /** Rute yang tetap boleh ditulis peran walikota. */
    private const DIKECUALIKAN = [
        'logout',
        'password.*',
        'profile.*',
        'two-factor.*',
        'user-profile-information.*',
        'user-password.*',
        'current-user-photo.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->peran === PeranPengguna::Walikota
            && ! $request->isMethodSafe()
            && ! $request->routeIs(self::DIKECUALIKAN)) {
            abort(403, 'Peran wali kota hanya dapat membaca data.');
        }

        return $next($request);
    }
}
