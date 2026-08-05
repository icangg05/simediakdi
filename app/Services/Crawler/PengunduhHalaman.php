<?php

namespace App\Services\Crawler;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Psr\Http\Message\UriInterface;
use Spatie\Robots\RobotsTxt;

/**
 * Satu-satunya tempat aplikasi mengunduh halaman dari luar.
 *
 * Menyatukan empat kewajiban yang mudah terlupa kalau tersebar: penjagaan SSRF
 * termasuk pada setiap hop redirect, batas ukuran unduhan, jeda antar
 * permintaan ke domain yang sama, dan robots.txt.
 */
class PengunduhHalaman
{
    private Client $klien;

    public function __construct(private ValidatorUrl $validator)
    {
        $this->klien = new Client([
            RequestOptions::TIMEOUT => config('crawler.timeout'),
            RequestOptions::CONNECT_TIMEOUT => 10,
            RequestOptions::HEADERS => [
                'User-Agent' => config('crawler.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
            RequestOptions::ALLOW_REDIRECTS => [
                'max' => 5,
                'strict' => true,
                'referer' => false,
                'track_redirects' => false,
                // Redirect adalah lubang SSRF paling sering terlewat: URL awal
                // lolos validasi, lalu situsnya mengalihkan ke 169.254.169.254.
                'on_redirect' => function (mixed $permintaan, mixed $tanggapan, UriInterface $tujuan) {
                    $this->validator->pastikanAman((string) $tujuan);
                },
            ],
        ]);
    }

    /**
     * @throws UrlDitolak URL internal, skema salah, atau dilarang robots.txt
     * @throws GagalMengunduh jaringan, HTTP bukan 2xx, atau isi terlalu besar
     */
    public function unduh(string $url): string
    {
        $this->validator->pastikanAman($url);

        if (! $this->diizinkanRobots($url)) {
            throw new UrlDitolak("robots.txt situs melarang pengambilan {$url}.");
        }

        $this->tahanSejenak($url);

        try {
            $tanggapan = $this->klien->send(new Request('GET', $url), [RequestOptions::STREAM => true]);
        } catch (GuzzleException $e) {
            $status = method_exists($e, 'getResponse') ? $e->getResponse()?->getStatusCode() : null;

            throw new GagalMengunduh("Tidak dapat menghubungi {$url}: {$e->getMessage()}", $status, $e);
        }

        if ($tanggapan->getStatusCode() >= 300) {
            throw new GagalMengunduh(
                "{$url} menjawab HTTP {$tanggapan->getStatusCode()}.",
                $tanggapan->getStatusCode(),
            );
        }

        return $this->bacaTerbatas($tanggapan->getBody(), $url);
    }

    /** Membaca stream sampai batas, bukan mempercayai header Content-Length. */
    private function bacaTerbatas(mixed $badan, string $url): string
    {
        $batas = (int) config('crawler.maks_unduh_byte');
        $isi = '';

        while (! $badan->eof()) {
            $isi .= $badan->read(8192);

            if (strlen($isi) > $batas) {
                throw new GagalMengunduh("Isi {$url} melebihi batas unduhan ".round($batas / 1024 / 1024, 1).' MB.');
            }
        }

        return $isi;
    }

    private function diizinkanRobots(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $skema = parse_url($url, PHP_URL_SCHEME);

        $isi = Cache::remember("robots:{$skema}://{$host}", now()->addHours(12), function () use ($skema, $host) {
            try {
                // Sengaja tanpa $this->unduh(): itu akan memanggil balik metode
                // ini dan berputar tanpa henti.
                $this->validator->pastikanAman("{$skema}://{$host}/robots.txt");

                return (string) $this->klien->get("{$skema}://{$host}/robots.txt")->getBody();
            } catch (GuzzleException|UrlDitolak) {
                // Tidak ada robots.txt berarti tidak ada larangan.
                return '';
            }
        });

        return RobotsTxt::create($isi)->allows($url, config('crawler.user_agent'));
    }

    /**
     * ponytail: jeda berbasis cache, bukan kunci lintas proses. Tiga worker
     * queue bisa lolos bersamaan dalam kasus balapan yang jarang. Ganti dengan
     * Cache::lock per host kalau ada media yang mengeluh soal beban.
     */
    private function tahanSejenak(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        $jeda = (int) config('crawler.delay_ms');

        if ($jeda <= 0 || ! $host) {
            return;
        }

        $kunci = "crawl:jeda:{$host}";
        $terakhir = Cache::get($kunci);

        if ($terakhir !== null) {
            $selisihMs = (int) ((microtime(true) - (float) $terakhir) * 1000);

            if ($selisihMs < $jeda) {
                usleep(($jeda - $selisihMs) * 1000);
            }
        }

        Cache::put($kunci, microtime(true), now()->addMinutes(5));
    }
}
