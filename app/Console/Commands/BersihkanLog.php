<?php

namespace App\Console\Commands;

use App\Models\LogCrawl;
use Illuminate\Console\Command;

/**
 * Pembersih log crawl.
 *
 * Masa simpan tujuh hari, turun dari sembilan puluh. Tabel ini bertambah tiap
 * tiga jam untuk 28 sumber dan nilainya habis dalam hitungan hari: log dibaca
 * saat ada sumber yang gagal, dan yang gagal minggu lalu sudah diperbaiki atau
 * sudah gagal lagi sejak itu. Yang tersisa dari retensi panjang cuma tabel
 * membengkak yang ikut membesarkan setiap berkas cadangan mingguan.
 */
class BersihkanLog extends Command
{
    protected $signature = 'log:bersihkan {--hari=7 : Umur maksimal baris yang disimpan}';

    protected $description = 'Menghapus baris log crawl yang lebih tua dari masa simpan';

    public function handle(): int
    {
        $hari = (int) $this->option('hari');

        $dihapus = LogCrawl::where('dimulai_at', '<', now()->subDays($hari))->delete();

        $this->info("{$dihapus} baris log crawl lebih tua dari {$hari} hari dihapus.");

        return self::SUCCESS;
    }
}
