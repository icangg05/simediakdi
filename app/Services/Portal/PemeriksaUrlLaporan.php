<?php

namespace App\Services\Portal;

use App\Models\Artikel;
use App\Models\Kontrak;
use App\Models\Media;
use App\Models\Pemuatan;
use App\Services\Crawler\EkstraktorArtikel;
use App\Services\Crawler\EkstraktorWordPress;
use App\Services\Crawler\GagalMengunduh;
use App\Services\Crawler\NormalisasiUrl;
use App\Services\Crawler\PengunduhHalaman;
use App\Services\Crawler\UrlDitolak;
use App\Support\Waktu;

/**
 * Memeriksa satu URL laporan pemuatan sebelum media menekan "Kirim semua"
 * (F-49, F-50, F-51).
 *
 * Seluruh keputusan diambil di sini supaya pratinjau di layar dan baris yang
 * akhirnya tersimpan tidak pernah berbeda. Kalau pemeriksaan dan penyimpanan
 * memakai aturan masing-masing, media akan melihat "berhasil" lalu mendapat
 * penolakan tanpa penjelasan.
 */
class PemeriksaUrlLaporan
{
    public const BERHASIL = 'berhasil';

    public const SUDAH_TERCATAT = 'sudah_tercatat';

    public const DOMAIN_SALAH = 'domain_salah';

    public const GAGAL = 'gagal';

    public function __construct(
        private NormalisasiUrl $normalisasi,
        private PengunduhHalaman $pengunduh,
        private EkstraktorWordPress $wordpress,
        private EkstraktorArtikel $ekstraktor,
    ) {}

    /**
     * @return array<string, mixed> {url, url_kanonik, status, judul, tanggal,
     *                              pesan, pemuatan_id, artikel_id}
     */
    public function periksa(string $url, Media $media, Kontrak $kontrak): array
    {
        $url = trim($url);
        $kanonik = $this->normalisasi->kanonik($url);

        $hasil = [
            'url' => $url,
            'url_kanonik' => $kanonik,
            'status' => self::BERHASIL,
            'judul' => null,
            'tanggal' => null,
            'pesan' => null,
            'pemuatan_id' => null,
            'artikel_id' => null,
        ];

        if (! $this->domainCocok($kanonik, $media)) {
            return [
                ...$hasil,
                'status' => self::DOMAIN_SALAH,
                'pesan' => "Tautan ini bukan dari {$media->domain}. Portal hanya menerima berita yang terbit di media Anda sendiri.",
            ];
        }

        // Dicek terhadap URL kanonik, bukan URL yang ditempel: satu berita
        // sering dibagikan dengan parameter pelacak yang berbeda-beda.
        $sudahAda = Pemuatan::withoutGlobalScopes()
            ->where('kontrak_id', $kontrak->id)
            ->where('url', $kanonik)
            ->first(['id', 'judul', 'tanggal_muat', 'sumber_catatan']);

        if ($sudahAda !== null) {
            return [
                ...$hasil,
                'status' => self::SUDAH_TERCATAT,
                'judul' => $sudahAda->judul,
                'tanggal' => $sudahAda->tanggal_muat?->toDateString(),
                'pemuatan_id' => $sudahAda->id,
                'pesan' => $sudahAda->sumber_catatan === 'otomatis'
                    ? 'Sudah ditemukan sistem lewat crawler dan sudah dihitung ke kontrak.'
                    : 'Sudah pernah dilaporkan.',
            ];
        }

        // Artikel yang sudah ter-crawl memberi judul dan tanggal tanpa perlu
        // mengunduh ulang halamannya.
        $artikel = Artikel::withoutGlobalScopes()
            ->where('url_kanonik', $kanonik)
            ->first(['id', 'judul', 'dipublikasikan_at', 'diambil_at']);

        if ($artikel !== null) {
            return [
                ...$hasil,
                'judul' => $artikel->judul,
                'tanggal' => Waktu::tanggalWita($artikel->dipublikasikan_at ?? $artikel->diambil_at),
                'artikel_id' => $artikel->id,
            ];
        }

        return [...$hasil, ...$this->ekstrak($url)];
    }

    /**
     * Subdomain diterima, domain lain tidak.
     *
     * Beberapa media partner menerbitkan di subdomain (`daerah.contoh.id`)
     * sementara `media.domain` menyimpan domain utamanya. Menolak subdomain
     * berarti menolak laporan yang sah.
     */
    public function domainCocok(string $url, Media $media): bool
    {
        $host = $this->normalisasi->domain($url);
        $milik = mb_strtolower((string) $media->domain);

        if ($host === null || $milik === '') {
            return false;
        }

        return $host === $milik || str_ends_with($host, '.'.$milik);
    }

    /** @return array<string, mixed> */
    private function ekstrak(string $url): array
    {
        try {
            $hasil = $this->ekstraktor->ekstrak($this->pengunduh->unduh($url), $url);
        } catch (UrlDitolak $e) {
            return ['status' => self::GAGAL, 'pesan' => $e->getMessage()];
        } catch (GagalMengunduh $e) {
            $hasil = null;
        }

        if ($hasil === null || $hasil->terlaluPendek() || $hasil->judul === null) {
            $hasil = $this->wordpress->ekstrak($url) ?? $hasil;
        }

        if ($hasil === null || $hasil->judul === null) {
            return [
                'status' => self::GAGAL,
                'pesan' => 'Halaman tidak bisa dibaca otomatis. Isi judul dan tanggalnya sendiri di bawah.',
            ];
        }

        return [
            'judul' => $hasil->judul,
            'tanggal' => Waktu::tanggalWita($hasil->dipublikasikanAt ?? now()),
        ];
    }
}
