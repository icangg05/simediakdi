<?php

namespace App\Enums;

enum StatusGerbangMutu: string
{
    case Blocked = 'blocked';
    case NeedsReview = 'needs_review';
    case Passed = 'passed';
    case Revoked = 'revoked';

    /**
     * Satu-satunya status yang mengizinkan artikel masuk antrean sentimen.
     *
     * Ditulis sebagai daftar putih, bukan daftar hitam. Status baru yang
     * ditambahkan nanti akan otomatis memblokir sampai ada yang sengaja
     * memasukkannya ke sini, dan itu arah kegagalan yang benar.
     */
    public function mengizinkanSentimen(): bool
    {
        return $this === self::Passed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Blocked => 'Diblokir',
            self::NeedsReview => 'Perlu review',
            self::Passed => 'Lulus',
            self::Revoked => 'Dicabut',
        };
    }
}
