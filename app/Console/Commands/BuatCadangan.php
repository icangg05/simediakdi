<?php

namespace App\Console\Commands;

use App\Services\CadanganDatabase;
use App\Services\CadanganGagal;
use Illuminate\Console\Command;

/**
 * Cadangan basis data terjadwal.
 *
 * Dijadwalkan tiap Senin pukul 03.00 WITA, lihat routes/console.php. Jalurnya
 * sama persis dengan tombol di halaman admin, termasuk aturan satu berkas per
 * minggu, jadi jadwal yang berjalan setelah admin membuat cadangan manual di
 * minggu yang sama akan menimpa berkas manual itu, bukan menambah berkas baru.
 */
class BuatCadangan extends Command
{
    protected $signature = 'cadangan:buat';

    protected $description = 'Membuat cadangan basis data, satu berkas per minggu';

    public function handle(CadanganDatabase $cadangan): int
    {
        try {
            $hasil = $cadangan->buat(CadanganDatabase::TIMEOUT_JADWAL);
        } catch (CadanganGagal $e) {
            // Dua baris, karena keduanya dibutuhkan saat membaca log seminggu
            // kemudian: apa yang gagal, dan keluaran mentah pg_dump yang
            // menjelaskan sebabnya.
            $this->error($e->getMessage());

            if ($e->catatan !== '') {
                $this->line($e->catatan);
            }

            return self::FAILURE;
        }

        $this->info('Cadangan '.$hasil['nama'].' selesai dibuat.');

        if ($hasil['ditimpa'] > 0) {
            $this->line($hasil['ditimpa'].' cadangan lain dari minggu yang sama ditimpa.');
        }

        if ($hasil['dibuang'] > 0) {
            $this->line($hasil['dibuang'].' cadangan terlama dibuang, batas simpan '.CadanganDatabase::SIMPAN_TERAKHIR.' berkas.');
        }

        return self::SUCCESS;
    }
}
