<?php

namespace App\Support;

final class IstilahAntarmuka
{
    /** Seragamkan istilah lama pada teks yang akan ditampilkan kepada pengguna. */
    public static function sentimen(mixed $nilai): mixed
    {
        if (is_array($nilai)) {
            return array_map(self::sentimen(...), $nilai);
        }

        if (! is_string($nilai)) {
            return $nilai;
        }

        return preg_replace(
            [
                '/\bBERNADA\b/u', '/\bBernada\b/u', '/\bbernada\b/u',
                '/\bNADANYA\b/u', '/\bNadanya\b/u', '/\bnadanya\b/u',
                '/\bNADA\b/u', '/\bNada\b/u', '/\bnada\b/u',
            ],
            [
                'BERSENTIMEN', 'Bersentimen', 'bersentimen',
                'SENTIMENNYA', 'Sentimennya', 'sentimennya',
                'SENTIMEN', 'Sentimen', 'sentimen',
            ],
            $nilai,
        ) ?? $nilai;
    }
}
