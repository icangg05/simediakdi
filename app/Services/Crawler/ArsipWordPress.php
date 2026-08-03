<?php

namespace App\Services\Crawler;

use Throwable;

/**
 * Menarik arsip lama lewat endpoint daftar WP REST.
 *
 * RSS hanya memuat 10 sampai 50 tulisan terbaru, jadi korpus untuk gold set
 * tidak bisa dibangun darinya tanpa menunggu berminggu-minggu. Endpoint daftar
 * WordPress bisa menyusuri arsip ke belakang lima puluh tulisan sekali jalan.
 *
 * Inilah pemakaian WP REST yang tepat. Untuk artikel tunggal ia justru lebih
 * lambat daripada mengunduh halamannya (lihat EkstraktorWordPress), tapi di
 * sini satu permintaan menggantikan lima puluh, dan itu jauh lebih ringan
 * bagi situs medianya daripada lima puluh unduhan halaman penuh.
 */
class ArsipWordPress
{
    /** Cukup besar untuk hemat permintaan, cukup kecil agar di bawah batas 5 MB. */
    private const PER_HALAMAN = 50;

    public function __construct(
        private PengunduhHalaman $pengunduh,
        private EkstraktorWordPress $ekstraktor,
        private NormalisasiUrl $normalisasi,
    ) {}

    /**
     * Satu halaman arsip.
     *
     * @return list<array{item: ItemFeed, hasil: HasilEkstraksi}>|null
     *                                                                null berarti situsnya tidak punya arsip yang bisa dibaca
     */
    public function halaman(string $host, int $halaman): ?array
    {
        // _embed sengaja tidak dipakai: pada lima puluh tulisan sekaligus ia
        // melipatgandakan ukuran respons sampai menembus batas unduhan, demi
        // nama penulis dan gambar utama yang tidak dibutuhkan untuk pelabelan.
        $url = "https://{$host}/wp-json/wp/v2/posts?".http_build_query([
            'per_page' => self::PER_HALAMAN,
            'page' => $halaman,
            'orderby' => 'date',
            'order' => 'desc',
        ]);

        try {
            $badan = $this->pengunduh->unduh($url);
        } catch (UrlDitolak|GagalMengunduh) {
            // Halaman melewati akhir arsip menjawab 400, dan itu tanda berhenti
            // yang wajar, bukan kegagalan.
            return null;
        }

        $json = json_decode($badan, true);

        if (! is_array($json) || $json === []) {
            return null;
        }

        $hasil = [];

        foreach ($json as $pos) {
            $tautan = $pos['link'] ?? null;

            if (! is_string($tautan) || $tautan === '' || ! isset($pos['content']['rendered'])) {
                continue;
            }

            try {
                $ekstraksi = $this->ekstraktor->dariPos($pos);
            } catch (Throwable) {
                continue;
            }

            if ($ekstraksi->isi === null) {
                continue;
            }

            $hasil[] = [
                'item' => new ItemFeed(
                    judul: $ekstraksi->judul ?? '(tanpa judul)',
                    url: $this->normalisasi->absolutkan($tautan, "https://{$host}/"),
                    dipublikasikanAt: $ekstraksi->dipublikasikanAt,
                    ringkasan: $ekstraksi->ringkasan,
                    penulis: $ekstraksi->penulis,
                ),
                'hasil' => $ekstraksi,
            ];
        }

        return $hasil;
    }
}
