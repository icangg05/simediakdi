<?php

namespace App\Support;

use App\Services\Agregasi\RingkasanEksekutif;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Rentang tanggal yang dipilih pengguna.
 *
 * Dipakai seluruh halaman eksekutif supaya pembacaan parameter, batas rentang,
 * dan konversi WITA hanya ditulis satu kali. Tanggal yang dipilih pengguna
 * selalu berarti kalender Kendari, bukan UTC.
 */
readonly class Periode
{
    /** Rentang maksimal, agar satu permintaan tidak menyapu bertahun-tahun data. */
    private const MAKS_HARI = 366;

    public function __construct(
        public CarbonImmutable $dari,
        public CarbonImmutable $sampai,
    ) {}

    public static function dariRequest(Request $request, RingkasanEksekutif $ringkasan): self
    {
        [$bawaanDari, $bawaanSampai] = $ringkasan->rentangBawaan();

        $dari = self::tanggal($request->query('dari'), $bawaanDari);
        $sampai = self::tanggal($request->query('sampai'), $bawaanSampai);

        // Rentang terbalik biasanya salah ketik, bukan permintaan sungguhan.
        if ($dari->greaterThan($sampai)) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        if ($dari->diffInDays($sampai) > self::MAKS_HARI) {
            $dari = $sampai->subDays(self::MAKS_HARI);
        }

        return new self(dari: $dari, sampai: $sampai);
    }

    private static function tanggal(mixed $nilai, CarbonImmutable $bawaan): CarbonImmutable
    {
        if (! is_string($nilai) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai) !== 1) {
            return $bawaan;
        }

        try {
            return CarbonImmutable::parse($nilai);
        } catch (\Throwable) {
            return $bawaan;
        }
    }

    /** Batas bawah dalam UTC, setara pukul 00.00 WITA pada tanggal itu. */
    public function mulaiUtc(): CarbonImmutable
    {
        return Waktu::awalHari($this->dari->toDateString());
    }

    public function akhirUtc(): CarbonImmutable
    {
        return Waktu::akhirHari($this->sampai->toDateString());
    }

    /** @return array<string, mixed> */
    public function untukInertia(): array
    {
        return [
            'periode' => [
                'dari' => $this->dari->toDateString(),
                'sampai' => $this->sampai->toDateString(),
            ],
        ];
    }

    /** @return array<string, mixed> parameter untuk mempertahankan pilihan saat berpindah halaman */
    public function kueri(): array
    {
        return [
            'dari' => $this->dari->toDateString(),
            'sampai' => $this->sampai->toDateString(),
        ];
    }
}
