<?php

namespace App\Services\Crawler;

use Carbon\CarbonImmutable;

/** Satu entri hasil pembacaan feed, sebelum halamannya diunduh. */
readonly class ItemFeed
{
    public function __construct(
        public string $judul,
        public string $url,
        public ?CarbonImmutable $dipublikasikanAt = null,
        public ?string $ringkasan = null,
        public ?string $penulis = null,
    ) {}
}
