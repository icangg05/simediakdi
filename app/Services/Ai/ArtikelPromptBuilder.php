<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Artikel;

/**
 * Menyusun teks artikel yang dikirim ke Gemini. Dokumen 13 bagian 9.
 *
 * Isi artikel dikirim utuh, hanya dipotong pada batas huruf. Itu syarat agar
 * validasi bukti punya arti: kutipan diverifikasi terhadap teks yang benar
 * benar dikirim, jadi bukti dari paragraf yang tidak pernah sampai ke model
 * akan selalu dinyatakan tidak valid dan artikelnya berakhir di antrean review
 * tanpa sebab yang jelas.
 *
 * Yang tidak pernah dikirim: credential, log internal, catatan admin, data
 * pegawai, dokumen kontrak, dan apa pun yang tidak ada di badan berita publik.
 * Free tier boleh memakai isi permintaan untuk peningkatan produk.
 */
class ArtikelPromptBuilder
{
    public function build(Artikel $artikel): string
    {
        $bagian = [
            'KONTEKS YANG DINILAI:'."\n".$this->konteks(),
            'JUDUL:'."\n".trim($artikel->judul),
        ];

        if ($artikel->media !== null) {
            $bagian[] = 'MEDIA:'."\n".$artikel->media->nama;
        }

        if ($artikel->dipublikasikan_at !== null) {
            $bagian[] = 'TANGGAL:'."\n".$artikel->dipublikasikan_at->toIso8601String();
        }

        if ($artikel->ringkasan !== null && trim($artikel->ringkasan) !== '') {
            $bagian[] = 'RINGKASAN:'."\n".trim($artikel->ringkasan);
        }

        $bagian[] = 'ISI:'."\n".$this->isi($artikel);

        return implode("\n\n", $bagian);
    }

    /**
     * Isi artikel apa adanya, hanya dipotong bila melewati batas.
     *
     * Pemotongan ditandai supaya model tahu artikelnya belum habis dan tidak
     * menyimpulkan sesuatu dari ketiadaan bagian penutup.
     */
    public function isi(Artikel $artikel): string
    {
        $isi = trim((string) $artikel->isi);
        $batas = (int) config('ai.maks_huruf_isi');

        if (mb_strlen($isi) <= $batas) {
            return $isi;
        }

        return mb_substr($isi, 0, $batas)."\n[isi artikel dipotong pada batas {$batas} huruf]";
    }

    /**
     * Teks yang dipakai untuk memvalidasi kutipan bukti.
     *
     * Harus persis apa yang dikirim ke model, termasuk judul dan ringkasan,
     * karena model boleh mengutip dari bagian mana pun yang ia terima.
     */
    public function teksTerkirim(Artikel $artikel): string
    {
        return implode("\n", array_filter([
            $artikel->judul,
            $artikel->ringkasan,
            $this->isi($artikel),
        ]));
    }

    /**
     * Subjek yang dinilai, dibaca dari config/pantauan.php.
     *
     * Kata kuncinya ikut dikirim sebagai penunjuk, bukan sebagai aturan.
     * Prompt di resources/prompts yang memuat aturan inklusi dan eksklusinya,
     * dan di situlah tempatnya: aturan yang panjang di sini akan diulang pada
     * setiap permintaan tanpa pernah berubah.
     */
    private function konteks(): string
    {
        return config('pantauan.nama')
            ."\n".'Kata kunci: '.implode(', ', config('pantauan.kata_kunci'));
    }
}
