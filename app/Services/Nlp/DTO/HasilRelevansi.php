<?php

namespace App\Services\Nlp\DTO;

readonly class HasilRelevansi
{
    public function __construct(
        public int $artikelId,
        public bool $relevan,
        public float $keyakinan,
    ) {}

    /** @param array{id: int, relevan: bool, keyakinan: float} $baris */
    public static function dariArray(array $baris): self
    {
        return new self(
            artikelId: (int) $baris['id'],
            relevan: (bool) $baris['relevan'],
            keyakinan: (float) $baris['keyakinan'],
        );
    }
}
