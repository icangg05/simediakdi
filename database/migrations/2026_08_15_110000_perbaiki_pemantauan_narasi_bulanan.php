<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Melepas status final yang berasal dari narasi 30 hari bergulir.
 *
 * Migrasi pembuat tabel awal sudah diperketat untuk instalasi baru. Migrasi
 * terpisah ini diperlukan bagi server yang telanjur menjalankan versi awalnya,
 * termasuk baris Juli 2026 yang menunjuk rentang 16 Juli-14 Agustus.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pemantauan_narasi_bulanan as p')
            ->leftJoin('narasi_eksekutif as n', 'n.id', '=', 'p.narasi_eksekutif_id')
            ->orderBy('p.id')
            ->select([
                'p.id',
                'p.bulan',
                'p.status',
                'p.dikunci',
                'p.narasi_eksekutif_id',
                'n.periode',
                'n.dari',
                'n.sampai',
            ])
            ->each(function (object $baris): void {
                $bulan = CarbonImmutable::parse((string) $baris->bulan, 'Asia/Makassar')->startOfMonth();
                $tautanSah = $baris->narasi_eksekutif_id !== null
                    && $baris->periode === '30d'
                    && (string) $baris->dari === $bulan->toDateString()
                    && (string) $baris->sampai === $bulan->endOfMonth()->toDateString();

                if ($tautanSah) {
                    return;
                }

                $perubahan = ['narasi_eksekutif_id' => null, 'updated_at' => now()];

                if ($baris->status === 'selesai' || (bool) $baris->dikunci) {
                    $perubahan += [
                        'status' => 'menunggu',
                        'dikunci' => false,
                        'pemeriksaan' => 0,
                        'galat' => null,
                        'mulai_at' => null,
                        'selesai_at' => null,
                        'gagal_at' => null,
                    ];
                }

                DB::table('pemantauan_narasi_bulanan')
                    ->where('id', $baris->id)
                    ->update($perubahan);
            });
    }

    public function down(): void
    {
        // Tautan yang salah sengaja tidak dipulihkan.
    }
};
