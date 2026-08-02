<?php

namespace App\Services\Crawler;

/**
 * Mengubah potongan HTML menjadi teks bersih.
 *
 * Dipakai kedua ekstraktor supaya isi artikel dari WP REST dan dari Readability
 * berbentuk sama persis — kalau berbeda, hash isi dan simhash ikut berbeda dan
 * deduplikasi gagal mengenali artikel yang sebenarnya sama.
 */
class PembersihHtml
{
    /** Isi disimpan sebagai teks: model NLP tidak butuh tag, dan hash jadi stabil. */
    public function keTeks(string $html): string
    {
        // Paragraf jadi baris baru dulu, supaya kalimat tidak menyatu.
        $html = preg_replace('#</(p|div|h[1-6]|li|br)>#i', "\n", $html);
        $html = str_ireplace('<br>', "\n", $html);

        $teks = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $teks = preg_replace('/[ \t]+/u', ' ', $teks);
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
        return trim(preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($teks), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ));
    }
}
