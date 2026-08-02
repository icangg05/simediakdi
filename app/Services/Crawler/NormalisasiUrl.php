<?php

namespace App\Services\Crawler;

/**
 * Deduplikasi lapis 1: satu artikel dikenali dari URL kanoniknya.
 *
 * Media yang sama menyebarkan tautan berbeda untuk halaman yang sama —
 * dengan utm_source dari Facebook, dengan trailing slash, dengan host
 * berhuruf besar. Tanpa normalisasi, satu artikel masuk lima kali.
 */
class NormalisasiUrl
{
    /**
     * Parameter pelacakan yang dibuang. Parameter lain dipertahankan karena
     * banyak situs PHP memakai `?p=123` sebagai identitas halaman — membuangnya
     * akan menggabungkan artikel yang sebenarnya berbeda.
     *
     * @var list<string>
     */
    private const PARAMETER_PELACAK = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'fbclid', 'gclid', 'msclkid', 'igshid', 'mc_cid', 'mc_eid',
        'ref', 'source', 'amp', '_ga', 'spm',
    ];

    public function kanonik(string $url): string
    {
        $bagian = parse_url(trim($url));

        if ($bagian === false || ! isset($bagian['host'])) {
            return trim($url);
        }

        $skema = strtolower($bagian['scheme'] ?? 'https');
        $host = strtolower($bagian['host']);

        // www dan non-www hampir selalu halaman yang sama.
        $host = preg_replace('/^www\./', '', $host);

        // Port bawaan tidak membedakan apa pun.
        $port = isset($bagian['port']) && ! in_array($bagian['port'], [80, 443], strict: true)
            ? ':'.$bagian['port']
            : '';

        $jalur = $bagian['path'] ?? '/';
        $jalur = preg_replace('#/+#', '/', $jalur);
        $jalur = $jalur === '/' ? '/' : rtrim($jalur, '/');

        // AMP adalah salinan halaman yang sama dengan URL berbeda.
        $jalur = preg_replace('#/amp$#', '', $jalur) ?: '/';

        $kueri = $this->bersihkanKueri($bagian['query'] ?? '');

        // Fragmen tidak pernah membedakan dokumen.
        return $skema.'://'.$host.$port.$jalur.$kueri;
    }

    /** Domain tanpa `www.`, untuk mencocokkan artikel ke media. */
    public function domain(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }

    /** Ubah tautan relatif di halaman menjadi absolut terhadap halaman itu. */
    public function absolutkan(string $tautan, string $urlDasar): string
    {
        $tautan = trim($tautan);

        if ($tautan === '' || str_starts_with($tautan, 'http://') || str_starts_with($tautan, 'https://')) {
            return $tautan;
        }

        $dasar = parse_url($urlDasar);
        $skema = $dasar['scheme'] ?? 'https';
        $host = $dasar['host'] ?? '';

        if (str_starts_with($tautan, '//')) {
            return $skema.':'.$tautan;
        }

        if (str_starts_with($tautan, '/')) {
            return $skema.'://'.$host.$tautan;
        }

        $jalurDasar = rtrim(dirname($dasar['path'] ?? '/'), '/');

        return $skema.'://'.$host.$jalurDasar.'/'.$tautan;
    }

    private function bersihkanKueri(string $kueri): string
    {
        if ($kueri === '') {
            return '';
        }

        parse_str($kueri, $parameter);

        foreach (self::PARAMETER_PELACAK as $pelacak) {
            unset($parameter[$pelacak]);
        }

        if ($parameter === []) {
            return '';
        }

        // Urutkan agar ?a=1&b=2 dan ?b=2&a=1 menghasilkan URL kanonik yang sama.
        ksort($parameter);

        return '?'.http_build_query($parameter);
    }
}
