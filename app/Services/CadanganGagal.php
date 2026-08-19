<?php

namespace App\Services;

use RuntimeException;

/**
 * Kegagalan pembuatan cadangan yang sudah layak dibaca manusia.
 *
 * Dua teks, bukan satu, karena pemanggilnya menampilkan keduanya di tempat
 * berbeda: `getMessage()` menjadi judul galat, `catatan` menjadi baris kecil
 * berisi keluaran pg_dump atau langkah perbaikannya.
 */
class CadanganGagal extends RuntimeException
{
    public function __construct(string $pesan, public readonly string $catatan = '')
    {
        parent::__construct($pesan);
    }
}
