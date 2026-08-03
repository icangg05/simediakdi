<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\PeriksaKesehatanNlp;
use App\Enums\StatusDedup;
use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Models\LogCrawl;
use App\Models\Pemuatan;
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
            'nlp' => $this->kesehatanNlp(),
        ];
    }

    /**
     * Diisi command nlp:health yang berjalan tiap 5 menit, bukan dengan
     * memanggil layanan saat request, halaman admin tidak boleh ikut lambat
     * atau ikut gagal hanya karena model sedang sibuk.
     *
     * @return array{status: string, keterangan: string}
     */
    private function kesehatanNlp(): array
    {
        $status = PeriksaKesehatanNlp::statusTerakhir();

        if ($status === null) {
            return [
                'status' => 'kuning',
                'keterangan' => 'Belum pernah diperiksa. Pastikan scheduler berjalan.',
            ];
        }

        if ($status['sehat']) {
            return [
                'status' => 'hijau',
                'keterangan' => 'Model '.($status['model_sentimen'] ?? 'sentimen')
                    .' siap, diperiksa '.\Carbon\CarbonImmutable::parse($status['diperiksa_at'])->diffForHumans(),
            ];
        }

        return [
            'status' => ($status['gagal_berturut'] ?? 0) >= 3 ? 'merah' : 'kuning',
            'keterangan' => 'Layanan NLP tidak menjawab. Analisis menumpuk di antrean dan akan '
                .'diproses setelah layanan hidup, tidak ada data yang hilang.',
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
