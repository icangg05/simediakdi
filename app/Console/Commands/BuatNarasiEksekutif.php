<?php

namespace App\Console\Commands;

use App\Services\Agregasi\NarasiEksekutif;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Membuat bagian naratif dashboard eksekutif.
 *
 * Dijalankan scheduler, tidak pernah oleh request. Kegagalan satu periode tidak
 * boleh menghentikan periode lain: kuota Gemini bisa habis di tengah jalan, dan
 * periode yang sudah sempat dikerjakan tetap punya narasi baru.
 */
class BuatNarasiEksekutif extends Command
{
    protected $signature = 'narasi:eksekutif
        {--periode=* : Preset yang dikerjakan, salah satu dari today, 7d, 30d, 90d}';

    protected $description = 'Menulis ringkasan dan topik eksekutif dengan Gemini';

    public function handle(NarasiEksekutif $narasi): int
    {
        $daftar = $this->option('periode') ?: array_keys(NarasiEksekutif::PRESET);
        $tidakDikenal = array_diff($daftar, array_keys(NarasiEksekutif::PRESET));

        if ($tidakDikenal !== []) {
            $this->error('Periode tidak dikenal: '.implode(', ', $tidakDikenal).'.');

            return self::FAILURE;
        }

        $gagal = 0;

        foreach ($daftar as $periode) {
            try {
                $baris = $narasi->perbarui($periode);

                $this->line($baris === null
                    ? "  {$periode}: dilewati, bahannya belum berubah atau belum ada berita berlabel"
                    : "  {$periode}: narasi diperbarui, {$baris->jumlah_artikel} berita, ".count($baris->topik ?? []).' topik');
            } catch (Throwable $galat) {
                $gagal++;

                // Narasi lama tetap di tabel dan tetap tampil di halaman, jadi
                // kegagalan di sini tidak mengosongkan dashboard.
                Log::error('Narasi eksekutif gagal dibuat.', [
                    'periode' => $periode,
                    'galat' => $galat->getMessage(),
                ]);

                $this->error("  {$periode}: gagal, {$galat->getMessage()}");
            }
        }

        return $gagal === count($daftar) ? self::FAILURE : self::SUCCESS;
    }
}
