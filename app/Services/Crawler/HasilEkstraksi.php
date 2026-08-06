<?php

namespace App\Services\Crawler;

use Carbon\CarbonImmutable;

readonly class HasilEkstraksi
{
    public function __construct(
        public ?string $judul,
        public ?string $isi,
        public ?string $ringkasan,
        public ?string $penulis,
        public ?string $gambarUrl,
        public ?CarbonImmutable $dipublikasikanAt,
        public int $jumlahKata,
    ) {}

    /** Artikel di bawah ambang biasanya hanya teaser; ditandai untuk audit. */
    public function terlaluPendek(): bool
    {
        return $this->jumlahKata < (int) config('crawler.artikel.minimal_kata');
    }

    /** Judul yang bisa dipakai, dengan isi artikel sebagai jalan terakhir. */
    public function judulAtauCadangan(): string
    {
        return $this->judul ?? self::judulDariIsi($this->isi) ?? self::TANPA_JUDUL;
    }

    /**
     * Penanda terakhir, saat isinya pun tidak menyisakan kalimat yang bisa
     * dipakai. Dipakai juga oleh pemeriksaan data untuk menemukan baris yang
     * judulnya perlu diperbaiki tangan.
     */
    public const TANPA_JUDUL = '(tanpa judul)';

    /**
     * Judul cadangan yang dirakit dari kalimat pertama isi artikel.
     *
     * Bukan tebakan yang dipaksakan. Sebagian pos WordPress memang terbit tanpa
     * judul sama sekali, dan halaman aslinya pun kosong: `<title>` hanya berisi
     * nama situs, `og:title` sama, dan `<h1>` kosong. Tidak ada tempat lain
     * untuk mengambilnya selain isi beritanya sendiri.
     *
     * Dua bentuk yang ditangani. Sebagian redaksi menaruh judul sebagai baris
     * tersendiri di atas paragraf pembuka, dan itu dipakai apa adanya. Sisanya
     * langsung membuka dengan dateline seperti "KENDARI, NAMAMEDIA.COM" diikuti
     * tanda pisah, dan bagian itu dibuang lebih dulu karena ia nama kota dan
     * nama media, bukan isi beritanya.
     *
     * Tanda pisah ditulis sebagai kode titik kode, bukan karakternya langsung,
     * karena repositori ini melarang em dash dan en dash muncul di dalam berkas
     * mana pun. Yang dilarang adalah menulisnya, bukan mengenalinya di data
     * yang datang dari luar.
     */
    public static function judulDariIsi(?string $isi, int $maks = 120): ?string
    {
        $awal = '';

        foreach (preg_split('/\R/u', trim((string) $isi)) ?: [] as $baris) {
            if (trim($baris) !== '') {
                $awal = trim($baris);

                break;
            }
        }

        if ($awal === '') {
            return null;
        }

        $dateline = '/^.{0,60}?\s[\x{2014}\x{2013}]\s/u';

        if (preg_match($dateline, $awal) === 1) {
            $awal = trim((string) preg_replace($dateline, '', $awal, 1));
        } elseif (mb_strlen($awal) <= $maks) {
            // Baris pendek tanpa dateline memang judulnya, ditulis terpisah
            // dari paragraf pembuka oleh redaksinya sendiri.
            return $awal;
        }

        // Dipotong pada batas kata. Judul yang terputus di tengah kata terbaca
        // seperti data rusak, bukan seperti judul yang panjang.
        if (mb_strlen($awal) > $maks) {
            $potong = mb_strrpos(mb_substr($awal, 0, $maks + 1), ' ');
            $awal = rtrim(mb_substr($awal, 0, $potong ?: $maks), " \t,.;:");
        }

        return $awal ?: null;
    }
}
