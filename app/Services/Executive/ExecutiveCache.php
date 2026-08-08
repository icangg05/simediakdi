<?php

namespace App\Services\Executive;

use Illuminate\Support\Facades\Cache;

final class ExecutiveCache
{
    private const VERSION_KEY = 'executive:dashboard:version';

    public function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    public function bump(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
    }
}
