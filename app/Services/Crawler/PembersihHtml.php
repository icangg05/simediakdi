<?php

namespace App\Services\Crawler;

/**
 * Mengubah potongan HTML menjadi teks bersih.
 *
 * Dipakai kedua ekstraktor supaya isi artikel dari WP REST dan dari Readability
 * berbentuk sama persis. Isi yang bentuknya berbeda-beda membuat pencarian,
 * ekspor, dan penilaian model membaca artikel yang sama secara berbeda.
 */
class PembersihHtml
{
    /** Isi disimpan sebagai teks: model NLP tidak butuh tag. */
    public function keTeks(string $html): string
    {
        // Paragraf jadi baris baru dulu, supaya kalimat tidak menyatu.
        $html = preg_replace('#</(p|div|h[1-6]|li|br)>#i', "\n", $html);
        $html = str_ireplace('<br>', "\n", $html);

        $teks = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // `\h` menangkap seluruh spasi horizontal, bukan hanya spasi dan tab.
        // `[ \t]` melewatkan U+00A0 yang lahir dari `&nbsp;`, dan media daerah
        // memakainya berlimpah untuk mengatur jarak paragraf. Sisanya terbaca
        // sebagai spasi ganda di isi artikel, lalu ikut terhitung sebagai kata
        // dan ikut terkirim ke Gemini sebagai token yang dibayar.
        $teks = preg_replace('/\h+/u', ' ', $teks);

        // Spasi yang mengapit ganti baris dirapikan sekaligus, dan `\R`
        // menyeragamkan CRLF dari halaman lama menjadi LF. Tanpa langkah ini
        // baris kosong yang berisi satu spasi tidak pernah terhitung oleh
        // pemadatan di bawahnya, sehingga jarak antar paragraf tetap menganga.
        $teks = preg_replace('/\h*\R\h*/u', "\n", $teks);

        // Maksimal satu baris kosong antar paragraf.
        $teks = preg_replace('/\n{3,}/', "\n\n", $teks);

        return trim($teks);
    }

    /** str_word_count tidak aman untuk UTF-8; pisah pada spasi saja. */
    public function hitungKata(string $teks): int
    {
        $teks = trim($teks);

        return $teks === '' ? 0 : count(preg_split('/\s+/u', $teks, flags: PREG_SPLIT_NO_EMPTY));
    }

    public function rapikan(string $teks): string
    {
        // `[\h\v]` bukan `\s`. Tanpa penanda PCRE_UCP, `\s` hanya mengenal
        // spasi ASCII walaupun polanya bertanda `u`, jadi `&nbsp;` lolos utuh
        // ke judul dan ringkasan.
        return trim(preg_replace(
            '/[\h\v]+/u',
            ' ',
            html_entity_decode(strip_tags($teks), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ));
    }
}
