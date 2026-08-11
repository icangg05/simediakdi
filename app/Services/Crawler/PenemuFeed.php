<?php

namespace App\Services\Crawler;

use App\Models\Media;
use DOMDocument;
use DOMXPath;
use Throwable;

/**
 * Menebak alamat RSS sebuah media dari domainnya.
 *
 * Admin tidak lagi mengisi alamat feed sendiri. Yang dia tahu hanyalah nama
 * media dan alamat situsnya, dan dua hal itu memang yang seharusnya cukup.
 *
 * Urutannya sengaja: tag `<link rel="alternate">` di halaman depan lebih dulu,
 * jalur tebakan belakangan. Tag itu adalah pernyataan situsnya sendiri tentang
 * di mana feed-nya berada, sedangkan jalur tebakan hanyalah kebiasaan WordPress
 * yang kebetulan sering benar. Menebak lebih dulu berarti sesekali memilih feed
 * kategori acak padahal situsnya sudah menunjuk feed utamanya.
 *
 * Calon yang ditemukan tidak langsung dipercaya. Masing-masing diunduh dan
 * diurai, dan yang dipakai hanya yang benar-benar menghasilkan item. Alamat
 * yang menjawab HTTP 200 berisi halaman "not found" bergaya cantik adalah
 * keadaan yang umum, dan menyimpannya sebagai feed berarti menanam sumber yang
 * gagal tiap tiga puluh menit tanpa pernah ada yang tahu sebabnya.
 */
class PenemuFeed
{
    /**
     * Jalur yang dicoba kalau halaman depan tidak menyebutkan feed-nya.
     *
     * Empat pertama adalah bawaan WordPress, yang menopang sebagian besar
     * portal berita daerah. Dua terakhir untuk Blogger dan situs statis.
     */
    private const JALUR_TEBAKAN = ['/feed', '/feed/', '/rss', '/rss.xml', '/index.xml', '/atom.xml'];

    /**
     * Batas jumlah alamat yang diunduh dalam satu pencarian.
     *
     * Tanpa batas, satu media dengan halaman depan penuh tautan feed kategori
     * bisa menghabiskan puluhan permintaan ke server orang lain untuk satu kali
     * simpan. Delapan sudah lebih dari cukup: yang benar hampir selalu ada di
     * dua percobaan pertama.
     */
    private const MAKS_PERCOBAAN = 8;

    public function __construct(
        private PengunduhHalaman $pengunduh,
        private PembacaRss $pembacaRss,
        private NormalisasiUrl $normalisasi,
    ) {}

    /**
     * Alamat feed yang sudah terbukti berisi item, atau null kalau tidak ketemu.
     */
    public function cari(Media $media): ?string
    {
        $dasar = $this->urlDasar($media);

        if ($dasar === null) {
            return null;
        }

        $dicoba = 0;

        foreach ($this->calon($dasar) as $url) {
            if ($dicoba >= self::MAKS_PERCOBAAN) {
                break;
            }

            $dicoba++;

            if ($this->berisiItem($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * `url_website` lebih dipercaya daripada `domain`.
     *
     * Sebagian media tercatat dengan domain telanjang sementara situsnya hidup
     * di subdomain atau subdirektori, dan `url_website` adalah kolom yang
     * menyimpan alamat sebenarnya.
     */
    private function urlDasar(Media $media): ?string
    {
        $mentah = trim((string) ($media->url_website ?? ''));

        if ($mentah === '') {
            $domain = trim((string) ($media->domain ?? ''));

            if ($domain === '') {
                return null;
            }

            $mentah = "https://{$domain}";
        }

        if (! str_starts_with($mentah, 'http://') && ! str_starts_with($mentah, 'https://')) {
            $mentah = "https://{$mentah}";
        }

        return rtrim($mentah, '/');
    }

    /**
     * Daftar alamat yang akan dicoba, tanpa kembar, sesuai urutan kepercayaan.
     *
     * @return list<string>
     */
    private function calon(string $dasar): array
    {
        $calon = $this->dariTagLink($dasar);

        foreach (self::JALUR_TEBAKAN as $jalur) {
            $calon[] = $dasar.$jalur;
        }

        return array_values(array_unique($calon));
    }

    /**
     * Membaca `<link rel="alternate" type="application/rss+xml">` di halaman depan.
     *
     * @return list<string>
     */
    private function dariTagLink(string $dasar): array
    {
        try {
            $html = $this->pengunduh->unduh($dasar);
        } catch (Throwable) {
            // Halaman depan tidak bisa dibuka bukan alasan berhenti. Jalur
            // tebakan masih mungkin benar, dan sejumlah situs memblokir robot
            // di halaman depan tetapi membiarkan /feed lewat.
            return [];
        }

        $sebelumnya = libxml_use_internal_errors(true);

        $dokumen = new DOMDocument;
        $dokumen->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($sebelumnya);

        $simpul = (new DOMXPath($dokumen))->query(
            '//link[@rel="alternate"][@type="application/rss+xml" or @type="application/atom+xml"]/@href'
        );

        $hasil = [];

        foreach ($simpul ?? [] as $atribut) {
            $href = trim($atribut->nodeValue ?? '');

            if ($href !== '') {
                $hasil[] = $this->normalisasi->absolutkan($href, $dasar);
            }
        }

        return $hasil;
    }

    /** Alamat dianggap feed hanya kalau isinya terurai dan ada minimal satu item. */
    private function berisiItem(string $url): bool
    {
        try {
            return $this->pembacaRss->baca($this->pengunduh->unduh($url), $url) !== [];
        } catch (Throwable) {
            return false;
        }
    }
}
