<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Konversi WITA yang eksplisit.
 *
 * Aplikasi berjalan di UTC dan seluruh timestamp disimpan UTC — lihat alasannya
 * di config/app.php. Tapi pengguna sistem ini ada di Kendari: "berita hari ini"
 * berarti hari menurut WITA, bukan menurut UTC. Tanpa konversi di sini, angka
 * "hari ini" salah setiap pukul 00.00 sampai 08.00 waktu setempat.
 */
class Waktu
{
    public const ZONA = 'Asia/Makassar';

    /** Saat UTC yang setara dengan pukul 00.00 WITA hari ini. */
    public static function awalHariIni(): CarbonImmutable
    {
        return CarbonImmutable::now(self::ZONA)->startOfDay()->utc();
    }

    /** Saat UTC yang setara dengan pukul 00.00 WITA pada tanggal yang diminta. */
    public static function awalHari(string $tanggal): CarbonImmutable
    {
        return CarbonImmutable::parse($tanggal, self::ZONA)->startOfDay()->utc();
    }

    /** Saat UTC yang setara dengan akhir hari WITA pada tanggal yang diminta. */
    public static function akhirHari(string $tanggal): CarbonImmutable
    {
        return CarbonImmutable::parse($tanggal, self::ZONA)->endOfDay()->utc();
    }

    /** Tanggal WITA dari sebuah saat, untuk kolom `tanggal` di tabel ringkasan. */
    public static function tanggalWita(DateTimeInterface $saat): string
    {
        return CarbonImmutable::instance($saat)->setTimezone(self::ZONA)->toDateString();
    }
}
