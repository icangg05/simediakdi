<?php

namespace App\Console\Commands;

use App\Services\Agregasi\PenghitungKataKunci;
use App\Support\Waktu;
use Illuminate\Console\Command;

class HitungKataKunci extends Command
{
    protected $signature = 'hitung:kata-kunci
        {--hari=1 : Berapa hari ke belakang yang dihitung ulang}
        {--granularitas=harian : harian atau mingguan}';

    protected $description = 'Menghitung frekuensi kata kunci per periode beserta skor lonjakannya';

    public function handle(PenghitungKataKunci $penghitung): int
    {
        $granularitas = $this->option('granularitas');

        if (! in_array($granularitas, ['harian', 'mingguan'], strict: true)) {
            $this->error('Granularitas hanya harian atau mingguan.');

            return self::FAILURE;
        }

        $total = 0;

        for ($i = (int) $this->option('hari') - 1; $i >= 0; $i--) {
            $tanggal = Waktu::tanggalWita(now()->subDays($i));

            $baris = $penghitung->hitung($tanggal, $granularitas);

            $this->line("  {$tanggal}: {$baris} istilah");
            $total += $baris;
        }

        $this->info("{$total} baris kata kunci ditulis.");

        return self::SUCCESS;
    }
}
