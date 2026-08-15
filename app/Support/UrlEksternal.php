<?php

namespace App\Support;

final class UrlEksternal
{
    /**
     * URL sumber yang aman dipasang pada tautan antarmuka.
     *
     * Nilai artikel berasal dari feed dan basis data lama. Karena itu boundary
     * tampilan tetap membatasi skema walaupun pengunduh biasanya hanya menerima
     * HTTP(S). URL relatif, protocol-relative, dan skema aktif seperti
     * `javascript:` tidak pernah dikirim sebagai tujuan tautan.
     */
    public static function http(?string $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $url = trim($nilai);
        $bagian = parse_url($url);

        if (
            $url === ''
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! \is_array($bagian)
            || ! isset($bagian['scheme'], $bagian['host'])
            || ! \in_array(strtolower($bagian['scheme']), ['http', 'https'], true)
        ) {
            return null;
        }

        return $url;
    }
}
