<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Gemini menyebut artikel yang tidak pernah dikirim sebagai bahan narasi. */
class ArtikelNarasiTidakValid extends RuntimeException
{
    /** @param list<int> $artikelIds */
    public function __construct(public array $artikelIds)
    {
        parent::__construct(
            'Gemini mengembalikan artikel_id yang tidak ada di daftar masukan: '
            .implode(', ', $artikelIds).'.'
        );
    }
}
