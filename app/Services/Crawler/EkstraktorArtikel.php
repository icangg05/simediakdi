<?php

namespace App\Services\Crawler;

use Carbon\CarbonImmutable;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Throwable;

/**
 * Mengubah HTML halaman menjadi judul, isi, penulis, tanggal, dan gambar (F-02).
 *
 * Jalur cadangan. Situs WordPress ditangani EkstraktorWordPress lebih dulu
 * karena datanya pasti, bukan hasil tebakan. Kelas ini menangani sisanya:
 * situs non-WordPress, dan situs WordPress yang artikelnya tidak lewat
 * endpoint `posts`.
 *
 * Kualitas ekstraksi menentukan seluruh analisis di sprint berikutnya, dan
 * kesalahannya sulit dilacak kalau baru ketahuan belakangan. Karena itu isi
 * dikembalikan sebagai teks bersih, bukan HTML.
 */
class EkstraktorArtikel
{
    public function __construct(private PembersihHtml $pembersih) {}

    public function ekstrak(string $html, string $url): HasilEkstraksi
    {
        $konfigurasi = (new Configuration)
            ->setOriginalURL($url)
            ->setFixRelativeURLs(true)
            // Halaman berita Indonesia sering pendek; ambang bawaan membuang
            // artikel sah yang hanya 3 paragraf.
            ->setCharThreshold(250)
            ->setSummonCthulhu(false);

        $readability = new Readability($konfigurasi);

        try {
            $readability->parse($html);

            $isi = $this->pembersih->keTeks($readability->getContent() ?? '');
            $judul = $this->pembersih->rapikan($readability->getTitle() ?? '');
            $penulis = $this->pembersih->rapikan($readability->getAuthor() ?? '') ?: null;
            $gambar = $readability->getImage() ?: null;
            $ringkasan = $this->pembersih->rapikan($readability->getExcerpt() ?? '') ?: null;
        } catch (ParseException|Throwable) {
            // Readability gagal pada halaman yang strukturnya tidak lazim.
            // Jangan buang artikelnya: metadata dari feed masih berguna, dan
            // job akan menandainya supaya bisa diperiksa manual.
            $isi = '';
            $judul = '';
            $penulis = null;
            $gambar = null;
            $ringkasan = null;
        }

        return new HasilEkstraksi(
            judul: $judul ?: null,
            isi: $isi ?: null,
            ringkasan: $ringkasan ? mb_substr($ringkasan, 0, 600) : ($isi ? mb_substr($isi, 0, 600) : null),
            penulis: $penulis,
            gambarUrl: $gambar,
            dipublikasikanAt: $this->tanggalDariMeta($html),
            jumlahKata: $this->pembersih->hitungKata($isi),
        );
    }

    /**
     * Tanggal dari metadata halaman. Lebih bisa dipercaya daripada pubDate feed,
     * yang sering menunjukkan waktu feed dibuat ulang, bukan waktu terbit.
     */
    private function tanggalDariMeta(string $html): ?CarbonImmutable
    {
        $pola = [
            '/<meta[^>]+property=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']article:published_time["\']/i',
            '/<meta[^>]+itemprop=["\']datePublished["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<time[^>]+datetime=["\']([^"\']+)["\']/i',
        ];

        foreach ($pola as $regex) {
            if (preg_match($regex, $html, $cocok) === 1) {
                try {
                    return CarbonImmutable::parse($cocok[1])->utc();
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return null;
    }
}
