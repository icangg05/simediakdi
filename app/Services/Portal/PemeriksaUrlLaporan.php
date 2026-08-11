<?php

namespace App\Services\Portal;

use App\Models\Artikel;
use App\Models\Media;
use App\Services\Crawler\EkstraktorArtikel;
use App\Services\Crawler\EkstraktorWordPress;
use App\Services\Crawler\GagalMengunduh;
use App\Services\Crawler\NormalisasiUrl;
use App\Services\Crawler\PengunduhHalaman;
use App\Services\Crawler\UrlDitolak;
use App\Support\Waktu;

/**
 * Memeriksa satu URL laporan berita sebelum media menekan "Kirim semua"
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

    /**
     * Pesan untuk URL yang sudah tercatat, satu per tahap.
     *
     * Sebelumnya satu kalimat dipakai untuk ketiganya, dan kalimat itu
     * berbunyi "penilaiannya masih berjalan". Untuk berita yang sudah diputus
     * di luar pantauan itu keliru: media diberi tahu untuk menunggu sesuatu
     * yang tidak akan pernah datang, lalu menyimpulkan sistemnya tersangkut.
     *
     * Ini juga jawaban atas keheranan yang paling sering muncul, yaitu berita
     * yang belum pernah ditambahkan siapa pun tetapi sudah berstatus "sudah
     * ada". Crawler menyimpan seluruh isi feed lebih dulu, termasuk berita yang
     * kemudian dinilai di luar pantauan, jadi tercatat tidak berarti terpantau.
     *
     * @var array<string, string>
     */
    private const PESAN_TERCATAT = [
        'tampil' => 'Sudah ada di sistem dan tampil di Berita saya.',
        'diproses' => 'Sudah ada di sistem, penilaiannya masih berjalan. Belum tampil di Berita saya sampai penilaian itu selesai.',
        'di_luar_pantauan' => 'Sudah ada di sistem, tetapi dinilai di luar pantauan Pemkot Kendari, jadi tidak masuk Berita saya. Menambahkannya lagi tidak mengubah penilaian itu.',
        'gagal' => 'Sudah ada di sistem, tetapi halamannya tidak bisa dibaca sehingga penilaiannya tidak pernah berjalan. Laporkan ke Diskominfo kalau tautannya masih bisa dibuka dari peramban.',
    ];

    public function __construct(
        private NormalisasiUrl $normalisasi,
        private PengunduhHalaman $pengunduh,
        private EkstraktorWordPress $wordpress,
        private EkstraktorArtikel $ekstraktor,
    ) {}

    /**
     * @return array<string, mixed> {url, url_kanonik, status, judul, tanggal,
     *                              pesan, artikel_id, tahap}
     */
    public function periksa(string $url, Media $media): array
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
            'artikel_id' => null,
            // Hanya terisi untuk URL yang sudah tercatat. Selalu ada sebagai
            // kunci supaya bentuk barisnya seragam di layar.
            'tahap' => null,
        ];

        if (! $this->domainCocok($kanonik, $media)) {
            return [
                ...$hasil,
                'status' => self::DOMAIN_SALAH,
                'pesan' => "Tautan ini bukan dari {$media->domain}. Portal hanya menerima berita yang terbit di media Anda sendiri.",
            ];
        }

        // Sudah ditangkap crawler. Inilah yang dimaksud "sudah otomatis", dan
        // melaporkannya lagi tidak menambah apa pun.
        //
        // Dicek terhadap URL kanonik, bukan URL yang ditempel: satu berita
        // sering dibagikan dengan parameter pelacak yang berbeda-beda.
        $artikel = Artikel::withoutGlobalScopes()
            ->where('url_kanonik', $kanonik)
            // `status_proses` ikut dipilih karena tahapPortal() membacanya untuk
            // membedakan halaman yang gagal diunduh dari yang masih mengantre.
            ->first(['id', 'judul', 'dipublikasikan_at', 'diambil_at', 'status_proses']);

        if ($artikel !== null) {
            $tahap = $artikel->tahapPortal();

            return [
                ...$hasil,
                'status' => self::SUDAH_TERCATAT,
                'judul' => $artikel->judul,
                'tanggal' => Waktu::tanggalWita($artikel->dipublikasikan_at ?? $artikel->diambil_at),
                'artikel_id' => $artikel->id,
                'tahap' => $tahap,
                'pesan' => self::PESAN_TERCATAT[$tahap],
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
