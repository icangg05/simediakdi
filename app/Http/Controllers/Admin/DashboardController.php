<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatusDedup;
use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Models\KunciGemini;
use App\Models\LogCrawl;
use App\Models\Pemuatan;
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
            'antrean' => $this->antrean(),
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

        $pekanIni = Artikel::query()->asli()->where('diambil_at', '>=', $mulaiPekanIni)->count();
        $pekanLalu = Artikel::query()->asli()
            ->whereBetween('diambil_at', [$mulaiPekanLalu, $mulaiPekanIni])
            ->count();

        return [
            // Batas hari WITA, bukan UTC. whereDate('diambil_at', today())
            // akan salah setiap pukul 00.00-08.00 waktu Kendari.
            'artikel_hari_ini' => Artikel::query()->asli()
                ->where('diambil_at', '>=', Waktu::awalHariIni())
                ->count(),
            'artikel_pekan_ini' => $pekanIni,
            'selisih_pekan_lalu' => $pekanIni - $pekanLalu,
            'salinan_pekan_ini' => Artikel::query()
                ->where('status_dedup', StatusDedup::Salinan)
                ->where('diambil_at', '>=', $mulaiPekanIni)
                ->count(),
            'gagal_proses' => Artikel::query()->where('status_proses', 'gagal')->count(),
            'sumber_aktif' => SumberFeed::query()->where('aktif', true)->count(),
        ];
    }

    /**
     * Berapa artikel yang masih menunggu diproses, dipecah per tahap.
     *
     * Ada karena menunggu tanpa angka terasa seperti menunggu tanpa kepastian.
     * Penarikan arsip atau pergantian model menghasilkan ribuan artikel yang
     * harus dianalisis ulang, dan tanpa penunjuk ini satu-satunya cara
     * mengetahui kemajuannya adalah membuka terminal.
     *
     * Dibaca dari `artikel.status_proses`, bukan dari isi antrean Redis.
     * Alasannya: status artikel adalah kebenaran yang bertahan, sedangkan
     * antrean Redis kosong bukan berarti pekerjaannya selesai, bisa juga
     * berarti job-nya hilang.
     *
     * @return array<string, mixed>
     */
    private function antrean(): array
    {
        $per = Artikel::query()->asli()
            ->selectRaw('status_proses, count(*) as n')
            ->groupBy('status_proses')
            ->pluck('n', 'status_proses');

        $menunggu = (int) ($per['mentah'] ?? 0)
            + (int) ($per['isi_diambil'] ?? 0)
            + (int) ($per['dianalisis'] ?? 0);

        $tuntas = (int) ($per['selesai'] ?? 0)
            + (int) ($per['tidak_relevan'] ?? 0)
            + (int) ($per['perlu_review'] ?? 0);

        $total = $menunggu + $tuntas;

        return [
            'menunggu' => $menunggu,
            'tuntas' => $tuntas,
            'total' => $total,
            'persen' => $total > 0 ? round($tuntas / $total * 100, 1) : 100.0,
            'tahap' => [
                // Urutannya mengikuti rantai job, supaya terlihat di tahap mana
                // pekerjaan menumpuk.
                ['nama' => 'Menunggu isi diambil', 'jumlah' => (int) ($per['mentah'] ?? 0)],
                ['nama' => 'Menunggu relevansi', 'jumlah' => (int) ($per['isi_diambil'] ?? 0)],
                ['nama' => 'Menunggu sentimen', 'jumlah' => (int) ($per['dianalisis'] ?? 0)],
            ],
            'perlu_review' => (int) ($per['perlu_review'] ?? 0),
            'gagal' => Artikel::query()->where('status_proses', 'gagal')->count(),
        ];
    }

    /** Titik hijau, kuning, atau merah untuk crawler dan layanan NLP. */
    private function kesehatan(): array
    {
        // Bukan max(): fungsi agregat melewati cast, hasilnya string mentah.
        $crawlTerakhir = LogCrawl::query()->latest('dimulai_at')->first()?->dimulai_at;
        $jamSejakCrawl = $crawlTerakhir ? now()->diffInHours($crawlTerakhir, absolute: true) : null;

        $sumberMati = SumberFeed::query()->where('aktif', false)->where('gagal_berturut', '>=', 5)->count();

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
                'status' => $sumberMati === 0 ? 'hijau' : ($sumberMati > 3 ? 'merah' : 'kuning'),
                'keterangan' => $sumberMati === 0
                    ? 'Semua sumber feed berjalan normal.'
                    : "{$sumberMati} sumber dinonaktifkan otomatis. Periksa URL-nya di halaman sumber feed.",
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
     * media tidak melaporkan berita kritis sebagai realisasi kontrak, jadi
     * sistem hanya melihat sisi baiknya.
     */
    private function proporsiSumber(): array
    {
        $jumlah = Pemuatan::query()
            ->selectRaw('sumber_catatan, count(*) as jumlah')
            ->groupBy('sumber_catatan')
            ->pluck('jumlah', 'sumber_catatan');

        $otomatis = (int) ($jumlah['otomatis'] ?? 0);
        $laporanMedia = (int) ($jumlah['laporan_media'] ?? 0);
        $inputAdmin = (int) ($jumlah['input_admin'] ?? 0);
        $total = $otomatis + $laporanMedia + $inputAdmin;

        $persenMandiri = $total === 0 ? 0.0 : round($laporanMedia / $total * 100, 1);

        return [
            'otomatis' => $otomatis,
            'laporan_media' => $laporanMedia,
            'input_admin' => $inputAdmin,
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
            ->get(['id', 'media_id', 'judul', 'url', 'diambil_at', 'status_dedup', 'status_proses'])
            ->all();
    }
}
