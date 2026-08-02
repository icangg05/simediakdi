<?php

namespace App\Enums;

enum LabelSentimen: string
{
    case Negatif = 'negatif';
    case Netral = 'netral';
    case Positif = 'positif';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
