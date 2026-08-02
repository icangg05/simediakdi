<?php

namespace App\Enums;

enum StatusVerifikasi: string
{
    case Menunggu = 'menunggu';
    case Terverifikasi = 'terverifikasi';
    case Ditolak = 'ditolak';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
