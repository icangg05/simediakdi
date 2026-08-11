<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AntreanGemini;
use App\Models\Artikel;
use App\Models\KunciGemini;
use App\Models\LogCrawl;
use App\Models\PengaturanAi;
use App\Models\SumberFeed;
use App\Support\Waktu;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Dashboard', [
            'kpi' => $this->kpi(),
            'ekstraksi' => $this->ekstraksi(),
            'kesehatan' => $this->kesehatan(),
            'proporsiSumber' => $this->proporsiSumber(),
            'sumberBermasalah' => $this->sumberBermasalah(),
            'artikelTerbaru' => $this->artikelTerbaru(),
        ]);
    }

    /** Angka disajikan bersama pembandingnya, bukan berdiri sendiri. */
    private function kpi(): array
    {
        $mulaiPekanIni = now()->subDays(7);
        $mulaiPekanLalu = now()->subDays(14);

        $pekanIni = Artikel::query()->where('diambil_at', '>=', $mulaiPekanIni)->count();
        $pekanLalu = Artikel::query()
            ->whereBetween('diambil_at', [$mulaiPekanLalu, $mulaiPekanIni])
            ->count();

        return [
            // Batas hari WITA, bukan UTC. whereDate('diambil_at', today())
            // akan salah setiap pukul 00.00-08.00 waktu Kendari.
            'artikel_hari_ini' => Artikel::query()
                ->where('diambil_at', '>=', Waktu::awalHariIni())
                ->count(),
            'artikel_pekan_ini' => $pekanIni,
            'selisih_pekan_lalu' => $pekanIni - $pekanLalu,
            'gagal_proses' => Artikel::query()->where('status_proses', 'gagal')->count(),
            'sumber_aktif' => SumberFeed::query()->where('aktif', true)->count(),
        ];
    }

    /**
     * Kemajuan ekstraksi isi artikel mentah, tahap paling awal setelah crawl.
     *
     * Yang dipantau di sini adalah pengunduhan halaman, bukan penilaian Gemini.
     * Keduanya berjalan pada kecepatan yang berbeda jauh, dan menggabungkannya
     * dalam satu penunjuk membuat perkiraan waktu selesai tidak berarti apa-apa:
     * ekstraksi habis dalam hitungan menit sementara penilaian butuh berhari-hari.
     *
     * Dibaca dari `artikel.status_proses`, bukan dari isi antrean Redis.
     * Alasannya: status artikel adalah kebenaran yang bertahan, sedangkan
     * antrean Redis kosong bukan berarti pekerjaannya selesai, bisa juga
     * berarti job-nya hilang.
     *
     * @return array<string, mixed>
     */
    private function ekstraksi(): array
    {
        $per = Artikel::query()
            ->selectRaw('status_proses, count(*) as n')
            ->groupBy('status_proses')
            ->pluck('n', 'status_proses');

        $mentah = (int) ($per['mentah'] ?? 0);

        // Batas hari WITA, sama seperti KPI di atasnya. Backfill pun terhitung
        // hari ini, karena `diambil_at` mencatat kapan barisnya masuk, bukan
        // kapan beritanya terbit.
        $awalHari = Waktu::awalHariIni();

        $masuk = Artikel::query()->where('diambil_at', '>=', $awalHari)->count();
        $sisa = Artikel::query()
            ->where('diambil_at', '>=', $awalHari)
            ->where('status_proses', 'mentah')
            ->count();

        $laju = $this->lajuEkstraksi();

        return [
            'mentah' => $mentah,
            'masuk_hari_ini' => $masuk,
            'diekstrak_hari_ini' => $masuk - $sisa,
            'persen' => $masuk > 0 ? round(($masuk - $sisa) / $masuk * 100, 1) : 100.0,
            'laju_per_menit' => $laju,
            // Dikirim sebagai saat, bukan sebagai durasi. Durasi yang dihitung
            // di server basi seketika di layar yang menyegarkan sendiri,
            // sedangkan saat selesai tetap benar sampai tarikan berikutnya.
            'estimasi_selesai_at' => $laju > 0 && $mentah > 0
                ? now()->addSeconds((int) ceil($mentah / $laju * 60))->toJSON()
                : null,
            // Tahap sesudahnya, ditampilkan sebagai angka pendamping supaya
            // terlihat bahwa artikel yang selesai diekstrak tidak menguap.
            'belum_klasifikasi' => (int) ($per['isi_diambil'] ?? 0),
            'antre_ai' => AntreanGemini::query()->whereIn('status', ['menunggu', 'berjalan'])->count(),
            'gagal' => (int) ($per['gagal'] ?? 0),
        ];
    }

    /**
     * Artikel yang selesai diekstrak per menit, diukur bukan ditebak.
     *
     * Kecepatannya ditentukan jeda antar permintaan dan kecepatan server media
     * yang sedang ditarik, bukan angka yang bisa dituliskan di config. Jendela
     * sepuluh menit cukup panjang untuk meredam satu situs lambat, cukup pendek
     * untuk mengikuti keadaan sekarang.
     *
     * Dibatasi pada `isi_diambil` supaya artikel yang sudah lanjut ke penilaian
     * tidak ikut terhitung dua kali. Yang keburu dinilai dalam sepuluh menit itu
     * tidak terhitung sama sekali, jadi angkanya condong ke bawah, dan perkiraan
     * yang terlalu lama jauh lebih tidak merugikan daripada yang terlalu cepat.
     */
    private function lajuEkstraksi(): float
    {
        $menit = 10;

        $selesai = Artikel::query()
            ->where('status_proses', 'isi_diambil')
            ->where('updated_at', '>=', now()->subMinutes($menit))
            ->count();

        return round($selesai / $menit, 2);
    }

    /** Titik hijau, kuning, atau merah untuk crawler dan layanan NLP. */
    private function kesehatan(): array
    {
        // Bukan max(): fungsi agregat melewati cast, hasilnya string mentah.
        $crawlTerakhir = LogCrawl::query()->latest('dimulai_at')->first()?->dimulai_at;
        $jamSejakCrawl = $crawlTerakhir ? now()->diffInHours($crawlTerakhir, absolute: true) : null;

        // Diukur dari hitungan gagal saja, bukan dari kolom `aktif`.
        //
        // Dulu syaratnya sumber yang sudah dimatikan otomatis. Penonaktifan itu
        // sudah dicabut, jadi memakai `aktif` membuat lampu ini hijau selamanya
        // sekalipun ada sumber yang gagal berpuluh kali berturut-turut. Sumber
        // yang rusak tetap perlu terlihat, apalagi sekarang ia terus mencoba.
        $sumberRusak = SumberFeed::query()->where('gagal_berturut', '>=', 5)->count();

        return [
            'crawler' => [
                'status' => match (true) {
                    $jamSejakCrawl === null => 'merah',
                    $jamSejakCrawl > 3 => 'merah',
                    $jamSejakCrawl > 1 => 'kuning',
                    default => 'hijau',
                },
                'keterangan' => $crawlTerakhir
                    ? 'Crawl terakhir '.$crawlTerakhir->diffForHumans()
                    : 'Crawler belum pernah berjalan. Periksa scheduler dan worker queue.',
            ],
            'sumber' => [
                'status' => $sumberRusak === 0 ? 'hijau' : ($sumberRusak > 3 ? 'merah' : 'kuning'),
                'keterangan' => $sumberRusak === 0
                    ? 'Semua sumber feed berjalan normal.'
                    : "{$sumberRusak} sumber gagal 5 kali berturut-turut atau lebih. Periksa URL-nya di halaman media.",
            ],
            'gemini' => $this->kesehatanGemini(),
        ];
    }

    /**
     * Kunci Gemini terpasang atau tidak, tanpa memanggil API.
     *
     * Panggilan nyata sengaja tidak dilakukan di sini. Halaman admin tidak
     * boleh ikut lambat atau ikut gagal hanya karena penyedia sedang sibuk, dan
     * satu panggilan per pembukaan dashboard memakan kuota free tier untuk
     * pertanyaan yang jawabannya sudah ada di tabel `kunci_gemini`.
     *
     * @return array{status: string, keterangan: string}
     */
    private function kesehatanGemini(): array
    {
        if (! KunciGemini::query()->exists()) {
            return [
                'status' => 'merah',
                'keterangan' => 'Belum ada kunci API Gemini. Tambahkan di halaman Pengaturan, '
                    .'klasifikasi tidak bisa dijalankan tanpa itu.',
            ];
        }

        // Kuning, bukan merah. Kuota yang habis pulih sendiri, dan menandainya
        // bermasalah membuat keadaan yang normal terbaca sama gawatnya dengan
        // sistem yang belum dikonfigurasi sama sekali.
        if (! KunciGemini::query()->tersedia()->exists()) {
            return [
                'status' => 'kuning',
                'keterangan' => 'Semua kunci API Gemini sedang kena limit. Klasifikasi jalan lagi '
                    .'setelah kuotanya pulih, waktunya terlihat di halaman Pengaturan.',
            ];
        }

        return [
            'status' => 'hijau',
            'keterangan' => 'Model '.PengaturanAi::aktif()->model
                .' terkonfigurasi. Klasifikasi dijalankan manual dari Antrean Klasifikasi.',
        ];
    }

    /**
     * F-54. Kalau laporan mandiri melewati 40%, angka sentimen berisiko bias:
     * media memilih sendiri berita mana yang dilaporkan, dan berita kritis
     * jarang termasuk, jadi sistem hanya melihat sisi baiknya.
     *
     * Dihitung dari kolom `dilaporkan_oleh` di tabel artikel: terisi berarti
     * berita itu dikirim media lewat portal, kosong berarti crawler yang
     * menemukannya. Yang menentukan bias adalah komposisi bahan yang
     * dianalisis, dan bahan itu ada di tabel artikel.
     */
    private function proporsiSumber(): array
    {
        $total = Artikel::query()->count();
        $laporanMedia = Artikel::query()->whereNotNull('dilaporkan_oleh')->count();

        $persenMandiri = $total === 0 ? 0.0 : round($laporanMedia / $total * 100, 1);

        return [
            'otomatis' => max(0, $total - $laporanMedia),
            'laporan_media' => $laporanMedia,
            'total' => $total,
            'persen_mandiri' => $persenMandiri,
            'melewati_ambang' => $persenMandiri > 40,
        ];
    }

    private function sumberBermasalah(): array
    {
        return SumberFeed::query()
            ->with('media:id,nama')
            ->where('gagal_berturut', '>', 0)
            ->orderByDesc('gagal_berturut')
            ->limit(5)
            ->get(['id', 'media_id', 'nama', 'gagal_berturut', 'aktif', 'pesan_error_terakhir'])
            ->all();
    }

    private function artikelTerbaru(): array
    {
        return Artikel::query()
            ->with('media:id,nama')
            ->orderByDesc('diambil_at')
            ->limit(8)
            ->get(['id', 'media_id', 'judul', 'url', 'diambil_at', 'status_proses'])
            ->all();
    }
}
