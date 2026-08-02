<?php

namespace App\Enums;

enum TierMedia: string
{
    case Nasional = 'nasional';
    case Regional = 'regional';
    case Lokal = 'lokal';

    /** Pembobotan peringkat media. */
    public function bobot(): float
    {
        return match ($this) {
            self::Nasional => 1.5,
            self::Regional => 1.2,
            self::Lokal => 1.0,
        };
    }
}
