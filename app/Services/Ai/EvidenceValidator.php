<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Memastikan kutipan bukti benar-benar ada di artikel. Dokumen 13 bagian 13.
 *
 * Ini satu-satunya pemeriksaan yang bisa membedakan model yang membaca artikel
 * dari model yang mengarang alasan yang terdengar masuk akal. Alasan karangan
 * tetap berupa kalimat Indonesia yang rapi, tetap lolos skema, dan tetap masuk
 * dashboard, jadi tidak ada gejala lain yang bisa dipakai.
 *
 * Bukti tidak valid TIDAK membuat artikel menjadi tidak relevan. Yang gagal
 * adalah alasannya, bukan artikelnya, dan menghukum artikel karena kesalahan
 * model akan membuang berita yang mungkin memang relevan. Yang benar adalah
 * mengirimnya ke manusia.
 */
class EvidenceValidator
{
    /**
     * Kutipan sangat pendek selalu ditemukan di artikel mana pun.
     *
     * "Kendari" cocok di ratusan artikel dan tidak membuktikan apa pun.
     * Batas ini membuat bukti harus berupa potongan kalimat.
     */
    private const MINIMAL_HURUF = 20;

    /**
     * @param  list<string>  $bukti
     * @return string|null kode alasan bila tidak valid, null bila lolos
     */
    public function periksa(array $bukti, string $teksArtikel): ?string
    {
        $bukti = array_values(array_filter(
            array_map(trim(...), $bukti),
            fn (string $satu): bool => $satu !== '',
        ));

        if (count($bukti) < (int) config('ai.bukti.minimal')) {
            return 'bukti_kosong';
        }

        if (count($bukti) > (int) config('ai.bukti.maksimal')) {
            return 'bukti_berlebihan';
        }

        $artikel = $this->normalisasi($teksArtikel);

        foreach ($bukti as $satu) {
            $kutipan = $this->normalisasi($satu);

            if (mb_strlen($kutipan) < self::MINIMAL_HURUF) {
                return 'bukti_terlalu_pendek';
            }

            if (! str_contains($artikel, $kutipan)) {
                return 'bukti_tidak_ditemukan';
            }
        }

        return null;
    }

    /**
     * Menyamakan hal yang tidak membedakan makna.
     *
     * Model menyalin kutipan dengan tanda kutip yang berbeda bentuk, spasi
     * ganda yang hilang, dan tanda baca yang bergeser. Menolak karena itu
     * berarti hampir seluruh bukti yang benar ikut ditolak, dan validasi yang
     * selalu gagal sama tidak bergunanya dengan validasi yang selalu lolos.
     */
    private function normalisasi(string $teks): string
    {
        $teks = mb_strtolower($teks);
        $teks = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $teks) ?? $teks;
        $teks = preg_replace('/\s+/u', ' ', $teks) ?? $teks;

        return trim($teks);
    }
}
