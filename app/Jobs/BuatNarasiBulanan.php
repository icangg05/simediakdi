<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PemantauanNarasiBulanan;
use App\Services\Agregasi\NarasiEksekutif;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Membuat analisis satu bulan tanpa bergantung pada definisi opsi Artisan. */
class BuatNarasiBulanan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $pemantauanId,
        public string $bulan,
    ) {
        $this->onQueue('default');
    }

    public function handle(NarasiEksekutif $narasi): void
    {
        $narasi->perbaruiBulan(
            CarbonImmutable::parse($this->bulan.'-01', Waktu::ZONA)->startOfMonth(),
        );
    }

    /** Menutup status bila job mati sebelum service sempat mencatat galatnya. */
    public function failed(?Throwable $galat): void
    {
        $pantauan = PemantauanNarasiBulanan::find($this->pemantauanId);

        if ($pantauan === null || $pantauan->status === PemantauanNarasiBulanan::STATUS_SELESAI) {
            return;
        }

        $pesan = trim($galat?->getMessage() ?? 'Pekerjaan berhenti tanpa keterangan.');

        $pantauan->update([
            'status' => PemantauanNarasiBulanan::STATUS_GAGAL,
            'dikunci' => false,
            'galat' => $pesan !== '' ? $pesan : 'Pekerjaan berhenti tanpa keterangan.',
            'selesai_at' => null,
            'gagal_at' => now(),
        ]);
    }
}
