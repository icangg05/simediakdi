<?php

namespace App\Services\Dedup;

/**
 * Simhash 64-bit untuk deduplikasi lapis 2.
 *
 * Lapis 1 (URL kanonik) hanya menangkap tautan yang sama persis. Lapis ini
 * menangkap kasus yang sebenarnya paling sering: satu rilis Antara dimuat
 * ulang sepuluh media dengan judul diubah sedikit dan satu paragraf ditambah.
 * Tanpa ini, satu peristiwa terhitung sepuluh kali dan seluruh angka salah.
 */
class PenghitungSimhash
{
    /** Ukuran shingle. Satu kata terlalu peka pada urutan, empat terlalu kaku. */
    private const KATA_PER_SHINGLE = 3;

    public function hitung(string $teks): ?int
    {
        $shingle = $this->shingle($teks);

        if ($shingle === []) {
            return null;
        }

        // Satu penghitung per bit: bertambah kalau bit menyala di token,
        // berkurang kalau padam. Tanda akhirnya yang menentukan bit hasil.
        $penghitung = array_fill(0, 64, 0);

        foreach ($shingle as $token => $bobot) {
            $hash = $this->hash64((string) $token);

            for ($bit = 0; $bit < 64; $bit++) {
                $penghitung[$bit] += (($hash >> $bit) & 1) === 1 ? $bobot : -$bobot;
            }
        }

        $simhash = 0;

        for ($bit = 0; $bit < 64; $bit++) {
            if ($penghitung[$bit] > 0) {
                $simhash |= (1 << $bit);
            }
        }

        return $simhash;
    }

    /**
     * Jumlah bit yang berbeda. Semakin kecil, semakin mirip.
     *
     * Trik Kernighan (`n &= n - 1`) sengaja tidak dipakai: hash 64-bit di PHP
     * adalah int bertanda, dan saat nilainya PHP_INT_MIN operasi `n - 1` meluap
     * menjadi float sehingga hitungannya kacau. decbin() menangani bit tanda
     * dengan benar dan panjangnya selalu 64 untuk nilai negatif.
     */
    public function jarak(int $a, int $b): int
    {
        return substr_count(decbin($a ^ $b), '1');
    }

    public function mirip(int $a, int $b, ?int $ambang = null): bool
    {
        return $this->jarak($a, $b) <= ($ambang ?? (int) config('crawler.dedup.ambang_simhash'));
    }

    /**
     * Shingle beserta frekuensinya.
     *
     * @return array<string, int>
     */
    private function shingle(string $teks): array
    {
        $kata = preg_split(
            '/[^\p{L}\p{N}]+/u',
            mb_strtolower($teks),
            flags: PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        if (count($kata) < self::KATA_PER_SHINGLE) {
            // Teks sangat pendek: pakai katanya apa adanya daripada tidak sama
            // sekali, supaya judul kembar tetap terdeteksi.
            return array_count_values($kata);
        }

        $shingle = [];

        for ($i = 0; $i <= count($kata) - self::KATA_PER_SHINGLE; $i++) {
            $potongan = implode(' ', array_slice($kata, $i, self::KATA_PER_SHINGLE));
            $shingle[$potongan] = ($shingle[$potongan] ?? 0) + 1;
        }

        return $shingle;
    }

    /** 64 bit pertama dari MD5. Bukan untuk keamanan, hanya sebaran bit. */
    private function hash64(string $token): int
    {
        return unpack('J', substr(md5($token, true), 0, 8))[1];
    }
}
