<?php

namespace App\Services\Crawler;

use RuntimeException;
use Throwable;

class GagalMengunduh extends RuntimeException
{
    /**
     * Status HTTP kalau server sempat menjawab, null kalau gagal di jaringan.
     *
     * Pemanggil perlu membedakan keduanya: 404 membuktikan rutenya memang tidak
     * ada, sedangkan timeout hanya berarti sedang apes.
     */
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
