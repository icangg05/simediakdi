<?php

namespace App\Services\Crawler;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Mengambil isi artikel lewat WordPress REST API sebagai jaring pengaman
 * ketika Readability gagal atau hasilnya terlalu pendek.
 *
 * Pengukuran pada 30 media lampiran A: 26 punya WP REST aktif, dan pada 27
 * media lokal serta regional, mayoritas partner kontrak, angkanya 25 dari 27.
 * Satu permintaan mengembalikan judul, isi, tanggal, penulis, dan gambar utama
 * secara pasti, bukan hasil tebakan.
 *
 * Tetap jaring pengaman, bukan jalur utama. Pada situs yang page cache-nya
 * hidup, dan itu hampir semuanya, HTML datang dalam 147 ms sedangkan
 * /wp-json/ butuh 422 ms karena menembus cache dan menjalankan PHP serta
 * query MySQL. Menaruhnya di depan berarti membayar tiga kali lipat latensi
 * dan membebani hosting kecil media daerah untuk keuntungan yang, pada 22 dari
 * 27 artikel yang diuji, tidak ada.
 *
 * Bukan tipe sumber baru. Ini soal cara mengambil isi, bukan cara menemukan
 * berita, jadi pilihan tipe di form sumber feed tidak berubah. Halaman
 * `/portal/lapor` di sprint 5 memanggil kelas yang sama.
 */
class EkstraktorWordPress
{
    public function __construct(
        private PengunduhHalaman $pengunduh,
        private PembersihHtml $pembersih,
    ) {}

    /** Hasil ekstraksi, atau null kalau situsnya bukan WordPress atau artikelnya tidak ketemu. */
    public function ekstrak(string $url): ?HasilEkstraksi
    {
        if (! config('crawler.wordpress.aktif')) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $slug = $this->slug($url);

        if ($host === null || $slug === null) {
            return null;
        }

        // Situs yang sudah terbukti bukan WordPress tidak dicoba lagi hari ini.
        if (Cache::get($this->kunci($host)) === false) {
            return null;
        }

        try {
            $badan = $this->pengunduh->unduh($this->urlApi($host, $slug));
        } catch (UrlDitolak $e) {
            // robots.txt melarang /wp-json/. Keputusan pemilik situs, hormati
            // sampai besok lalu cek lagi.
            Cache::put($this->kunci($host), false, now()->addDay());

            return null;
        } catch (GagalMengunduh $e) {
            // Hanya 404 yang membuktikan rute REST tidak ada. Timeout atau 502
            // adalah gangguan sesaat, jangan mematikan jalur API sehari penuh
            // karena satu permintaan yang apes.
            if ($e->status === 404) {
                Cache::put($this->kunci($host), false, now()->addDay());
            }

            return null;
        }

        $json = json_decode($badan, true);

        if (! is_array($json)) {
            Cache::put($this->kunci($host), false, now()->addDay());

            return null;
        }

        Cache::put($this->kunci($host), true, now()->addDay());

        // Array kosong berarti API-nya hidup tapi artikel ini tidak lewat
        // endpoint `posts`, biasanya custom post type atau permalink `?p=123`.
        // Jalur API tetap dipertahankan untuk artikel lain di situs yang sama.
        if ($json === [] || ! isset($json[0]['content']['rendered'])) {
            return null;
        }

        return $this->dariPos($json[0]);
    }

    /**
     * Satu pos WP REST menjadi hasil ekstraksi.
     *
     * Publik karena dipakai juga oleh penarikan arsip massal, yang mengambil
     * puluhan pos sekaligus dari endpoint daftar alih-alih satu per slug.
     *
     * @param  array<string, mixed>  $pos
     */
    public function dariPos(array $pos): HasilEkstraksi
    {
        $isi = $this->pembersih->keTeks($pos['content']['rendered'] ?? '');
        $ringkasan = $this->pembersih->rapikan($pos['excerpt']['rendered'] ?? '');

        return new HasilEkstraksi(
            judul: $this->pembersih->rapikan($pos['title']['rendered'] ?? '') ?: null,
            isi: $isi ?: null,
            ringkasan: mb_substr($ringkasan ?: $isi, 0, 600) ?: null,
            penulis: $this->teks($pos, ['_embedded', 'author', 0, 'name']),
            gambarUrl: $this->teks($pos, ['_embedded', 'wp:featuredmedia', 0, 'source_url']),
            dipublikasikanAt: $this->waktu($pos['date_gmt'] ?? null),
            jumlahKata: $this->pembersih->hitungKata($isi),
        );
    }

    /**
     * `date_gmt` memang GMT, tapi nilainya dikirim tanpa penanda zona waktu
     * ("2026-08-02T11:44:31"). Kalau diurai tanpa menyebut UTC, Carbon memakai
     * zona aplikasi, dan di WITA hasilnya meleset delapan jam ke belakang.
     */
    private function waktu(?string $mentah): ?CarbonImmutable
    {
        if ($mentah === null || trim($mentah) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($mentah, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Segmen terakhir path. Menutup dua bentuk permalink yang dipakai media
     * Kendari: `/2026/08/02/judul-berita/` dan `/judul-berita/`.
     */
    private function slug(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $slug = basename(rtrim($path, '/'));

        return $slug === '' ? null : $slug;
    }

    private function urlApi(string $host, string $slug): string
    {
        // _embed menyertakan nama penulis dan gambar utama di respons yang sama,
        // jadi tetap satu permintaan, bukan tiga.
        return "https://{$host}/wp-json/wp/v2/posts?".http_build_query([
            'slug' => $slug,
            '_embed' => '1',
        ]);
    }

    /** @param list<string|int> $jalur */
    private function teks(array $data, array $jalur): ?string
    {
        foreach ($jalur as $kunci) {
            if (! is_array($data) || ! isset($data[$kunci])) {
                return null;
            }

            $data = $data[$kunci];
        }

        return is_string($data) && $data !== '' ? $data : null;
    }

    private function kunci(string $host): string
    {
        return "wp-rest:{$host}";
    }
}
