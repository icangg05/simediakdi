<?php

namespace App\Console\Commands;

use App\Models\LogCrawl;
use Illuminate\Console\Command;

class BersihkanLog extends Command
{
    protected $signature = 'log:bersihkan {--hari=90 : Umur maksimal baris yang disimpan}';

    protected $description = 'Menghapus baris log crawl yang lebih tua dari masa simpan';

    public function handle(): int
    {
        $hari = (int) $this->option('hari');

        $dihapus = LogCrawl::where('dimulai_at', '<', now()->subDays($hari))->delete();

        $this->info("{$dihapus} baris log crawl lebih tua dari {$hari} hari dihapus.");

        return self::SUCCESS;
    }
}
