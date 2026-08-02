<?php

namespace App\Enums;

enum PeranPengguna: string
{
    case Superadmin = 'superadmin';
    case Walikota = 'walikota';
    case Media = 'media';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'Admin Diskominfo',
            self::Walikota => 'Wali Kota dan Staf Khusus',
            self::Media => 'Pengelola Media',
        };
    }

    /** Prefix grup route yang menjadi beranda peran ini. */
    public function beranda(): string
    {
        return match ($this) {
            self::Superadmin => '/admin',
            self::Walikota => '/eksekutif',
            self::Media => '/portal',
        };
    }
}
