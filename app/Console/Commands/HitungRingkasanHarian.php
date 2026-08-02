<?php

namespace App\Console\Commands;

use App\Services\Agregasi\RingkasanHarian;
use App\Support\Waktu;
use Illuminate\Console\Command;

class HitungRingkasanHarian extends Command
{
    protected $signature = 'hitung:ringkasan-harian
        {--hari=1 : Berapa hari ke belakang yang ditulis ulang}
        {--tanggal= : Hitung satu tanggal WITA tertentu, format Y-m-d}';

    protected $description = 'Menulis ulang tabel ringkasan harian yang dibaca seluruh grafik';

    public function handle(RingkasanHarian $ringkasan): int
    {
        if ($tanggal = $this->option('tanggal')) {
            $baris = $ringkasan->hitung($tanggal);
            $this->info("{$tanggal}: {$baris} baris ringkasan ditulis.");

            return self::SUCCESS;
        }

        $hasil = $ringkasan->hitungMundur((int) $this->option('hari'));

        foreach ($hasil as $tanggal => $baris) {
            $this->line("  {$tanggal}: {$baris} baris");
        }

        $this->info('Ringkasan sampai '.Waktu::tanggalWita(now()).' diperbarui.');

        return self::SUCCESS;
    }
}
