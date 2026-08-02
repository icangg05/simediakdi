<?php

namespace App\Services\Nlp\DTO;

use App\Enums\LabelSentimen;

readonly class HasilSentimen
{
    public function __construct(
        public int $artikelId,
        public LabelSentimen $label,
        public float $skorNegatif,
        public float $skorNetral,
        public float $skorPositif,
        public float $keyakinan,
        public string $modelVersi,
    ) {}

    /** @param array<string, mixed> $baris */
    public static function dariArray(array $baris): self
    {
        return new self(
            artikelId: (int) $baris['id'],
            label: LabelSentimen::from($baris['label']),
            skorNegatif: (float) $baris['skor']['negatif'],
            skorNetral: (float) $baris['skor']['netral'],
            skorPositif: (float) $baris['skor']['positif'],
            keyakinan: (float) $baris['keyakinan'],
            modelVersi: (string) $baris['model_versi'],
        );
    }

    /**
     * F-12: hasil dengan keyakinan di bawah ambang belum boleh dianggap fakta.
     * Menyembunyikan ketidakpastian membuat sistem menyatakan hal yang tidak
     * diketahuinya.
     */
    public function perluReview(?float $ambang = null): bool
    {
        return $this->keyakinan < ($ambang ?? (float) config('nlp.ambang.sentimen'));
    }
}
