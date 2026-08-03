<?php

namespace App\Services\Crawler;

/**
 * Penjaga SSRF (dokumen 06 bagian 7).
 *
 * Admin bisa memasukkan URL sumber feed apa pun, termasuk alamat internal
 * jaringan atau `http://169.254.169.254/`. Di server cloud kesalahan ini
 * berujung pada kebocoran kredensial metadata, jadi setiap URL yang akan
 * diambil crawler harus lewat sini lebih dulu, tanpa pengecualian.
 */
class ValidatorUrl
{
    /**
     * Rentang yang tidak boleh dihubungi. Pemeriksaannya pada IP hasil resolusi
     * DNS, bukan pada teks host: `internal.contoh.id` bisa saja menunjuk 10.0.0.1.
     *
     * @var list<string>
     */
    private const RENTANG_TERLARANG = [
        '0.0.0.0/8',        // "this network"
        '10.0.0.0/8',       // privat
        '100.64.0.0/10',    // CGNAT
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local, termasuk metadata cloud
        '172.16.0.0/12',    // privat
        '192.0.0.0/24',     // IETF protocol assignments
        '192.168.0.0/16',   // privat
        '198.18.0.0/15',    // benchmarking
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved
    ];

    /** @throws UrlDitolak */
    public function pastikanAman(string $url): void
    {
        $bagian = parse_url($url);

        if ($bagian === false || ! isset($bagian['scheme'], $bagian['host'])) {
            throw new UrlDitolak("URL tidak dapat dibaca: {$url}");
        }

        if (! in_array(strtolower($bagian['scheme']), ['http', 'https'], strict: true)) {
            throw new UrlDitolak("Skema {$bagian['scheme']} tidak diizinkan. Hanya http dan https.");
        }

        $host = $bagian['host'];

        foreach ($this->resolusi($host) as $ip) {
            if ($this->terlarang($ip)) {
                throw new UrlDitolak("Host {$host} menunjuk alamat internal ({$ip}) dan tidak akan diambil.");
            }
        }
    }

    public function aman(string $url): bool
    {
        try {
            $this->pastikanAman($url);

            return true;
        } catch (UrlDitolak) {
            return false;
        }
    }

    /**
     * Seluruh alamat yang dituju host. Satu nama bisa menunjuk banyak IP, dan
     * cukup satu di antaranya internal untuk membuat URL-nya berbahaya.
     *
     * @return list<string>
     */
    private function resolusi(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        $semua = array_values(array_filter([...$ipv4, ...$ipv6]));

        if ($semua === []) {
            throw new UrlDitolak("Host {$host} tidak dapat diresolusi. Periksa ejaan domainnya.");
        }

        return $semua;
    }

    private function terlarang(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // ::1 loopback, fc00::/7 unique local, fe80::/10 link-local.
            $biner = inet_pton($ip);

            return $biner === inet_pton('::1')
                || (ord($biner[0]) & 0xFE) === 0xFC
                || (ord($biner[0]) === 0xFE && (ord($biner[1]) & 0xC0) === 0x80)
                // ::ffff:a.b.c.d, IPv4 yang dibungkus IPv6.
                || (str_starts_with($biner, inet_pton('::ffff:0.0.0.0'))
                    && $this->terlarang(inet_ntop(substr($biner, 12))));
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        foreach (self::RENTANG_TERLARANG as $rentang) {
            [$jaringan, $bit] = explode('/', $rentang);

            $masker = -1 << (32 - (int) $bit);

            if ((ip2long($ip) & $masker) === (ip2long($jaringan) & $masker)) {
                return true;
            }
        }

        return false;
    }
}
