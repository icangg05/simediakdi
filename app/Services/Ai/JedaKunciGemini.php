<?php

declare(strict_types=1);

namespace App\Services\Ai;

use RuntimeException;

/** Semua kunci aktif masih berada dalam jeda singkat klasifikasi manual. */
class JedaKunciGemini extends RuntimeException
{
    public function __construct(public readonly int $sisaDetik)
    {
        parent::__construct("Semua kunci Gemini baru saja dipakai. Tunggu {$sisaDetik} detik lagi.");
    }
}
