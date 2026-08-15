<?php

namespace App\Console\Commands;

use App\Services\Agregasi\NarasiEksekutif;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Membuat bagian naratif dashboard dan laporan eksekutif.
 *
 * Dijalankan scheduler, tidak pernah oleh request. Kegagalan satu periode tidak
 * boleh menghentikan periode lain: kuota Gemini bisa habis di tengah jalan, dan
 * periode yang sudah sempat dikerjakan tetap punya narasi baru.
 */
class BuatNarasiEksekutif extends Command
{
    protected $signature = 'narasi:eksekutif
        {--periode=* : Preset yang dikerjakan, salah satu dari today, 7d, 30d, 90d}
        {--bulan=* : Bulan laporan tertentu dalam format YYYY-MM}';

    protected $description = 'Menulis ringkasan dashboard dan laporan eksekutif dengan Gemini';

    public function handle(NarasiEksekutif $narasi): int
    {
        $periodeDiminta = array_values(array_unique((array) $this->option('periode')));
        $bulanDiminta = array_values(array_unique((array) $this->option('bulan')));
        $daftar = $periodeDiminta === [] && $bulanDiminta === []
            ? array_keys(NarasiEksekutif::PRESET)
            : $periodeDiminta;
        $tidakDikenal = array_diff($daftar, array_keys(NarasiEksekutif::PRESET));

        if ($tidakDikenal !== []) {
            $this->error('Periode tidak dikenal: '.implode(', ', $tidakDikenal).'.');

            return self::FAILURE;
        }

        $bulan = [];

        foreach ($bulanDiminta as $nilai) {
            $awal = $this->awalBulan((string) $nilai);

            if ($awal === null) {
                $this->error("Bulan tidak valid: {$nilai}. Gunakan format YYYY-MM.");

                return self::FAILURE;
            }

            $bulan[(string) $nilai] = $awal;
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

        foreach ($bulan as $nilai => $awal) {
            try {
                $baris = $narasi->perbaruiBulan($awal);

                $this->line($baris === null
                    ? "  {$nilai}: dilewati, bahannya belum berubah atau belum ada berita berlabel"
                    : "  {$nilai}: narasi laporan diperbarui, {$baris->jumlah_artikel} berita, ".count($baris->topik ?? []).' topik');
            } catch (Throwable $galat) {
                $gagal++;

                Log::error('Narasi laporan bulanan gagal dibuat.', [
                    'bulan' => $nilai,
                    'galat' => $galat->getMessage(),
                ]);

                $this->error("  {$nilai}: gagal, {$galat->getMessage()}");
            }
        }

        $jumlahPekerjaan = count($daftar) + count($bulan);

        return $gagal === $jumlahPekerjaan ? self::FAILURE : self::SUCCESS;
    }

    private function awalBulan(string $nilai): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $nilai, $bagian) !== 1) {
            return null;
        }

        $tahun = (int) $bagian[1];
        $bulan = (int) $bagian[2];

        return checkdate($bulan, 1, $tahun)
            ? CarbonImmutable::create($tahun, $bulan, 1, 0, 0, 0, Waktu::ZONA)
            : null;
    }
}
