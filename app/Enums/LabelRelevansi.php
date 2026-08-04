<?php

namespace App\Enums;

enum LabelRelevansi: string
{
    case Relevan = 'relevan';
    case TidakRelevan = 'tidak_relevan';

    public function label(): string
    {
        return $this === self::Relevan ? 'Relevan' : 'Tidak relevan';
    }
}
