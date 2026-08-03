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

        $konteks = $penghitung->konteksAktif();
        $total = 0;

        for ($i = (int) $this->option('hari') - 1; $i >= 0; $i--) {
            $tanggal = Waktu::tanggalWita(now()->subDays($i));

            // Baris lintas konteks (konteks_pantauan_id null) dihitung juga:
            // halaman isu bisa dilihat tanpa memilih konteks.
            $baris = $penghitung->hitung($tanggal, null, $granularitas);

            foreach ($konteks as $satu) {
                $baris += $penghitung->hitung($tanggal, $satu->id, $granularitas);
            }

            $this->line("  {$tanggal}: {$baris} istilah");
            $total += $baris;
        }

        $this->info("{$total} baris kata kunci ditulis.");

        return self::SUCCESS;
    }
}
