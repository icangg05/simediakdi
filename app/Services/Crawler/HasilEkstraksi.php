<?php

namespace App\Services\Crawler;

use Carbon\CarbonImmutable;

readonly class HasilEkstraksi
{
    public function __construct(
        public ?string $judul,
        public ?string $isi,
        public ?string $ringkasan,
        public ?string $penulis,
        public ?string $gambarUrl,
        public ?CarbonImmutable $dipublikasikanAt,
        public int $jumlahKata,
    ) {}

    /** Artikel di bawah ambang biasanya hanya teaser; ditandai untuk audit. */
    public function terlaluPendek(): bool
    {
        return $this->jumlahKata < (int) config('crawler.artikel.minimal_kata');
    }
}
