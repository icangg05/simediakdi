<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LogCrawl;
use App\Models\SumberFeed;
use App\Support\KueriTabel;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class LogCrawlController extends Controller
{
    public function index(Request $request): Response
    {
        $log = KueriTabel::untuk(
            LogCrawl::query()->with('sumberFeed:id,nama,media_id', 'sumberFeed.media:id,nama'),
            $request,
        )
            ->cari(['pesan'])
            ->saring(['status' => 'status', 'sumber' => 'sumber_feed_id'])
            ->urut(['dimulai_at', 'jumlah_ditemukan', 'jumlah_baru'], 'dimulai_at', 'desc')
            ->halaman();

        return Inertia::render('admin/log-crawl/Index', [
            'log' => $log,
            'opsi' => [
                'status' => [
                    ['nilai' => 'berjalan', 'label' => 'Berjalan'],
                    ['nilai' => 'sukses', 'label' => 'Sukses'],
                    ['nilai' => 'sebagian', 'label' => 'Sebagian'],
                    ['nilai' => 'gagal', 'label' => 'Gagal'],
                ],
                'sumber' => SumberFeed::query()->orderBy('nama')->get(['id', 'nama'])
                    ->map(fn (SumberFeed $s) => ['nilai' => (string) $s->id, 'label' => $s->nama])->all(),
            ],
            'jumlahSumberAktif' => SumberFeed::where('aktif', true)->count(),
            // value() lewat model, bukan max(), supaya cast datetime ikut jalan
            // dan hasilnya ISO UTC seperti baris tabel di bawahnya.
            'crawlTerakhir' => LogCrawl::query()->latest('dimulai_at')->value('dimulai_at'),
            'crawlBerikutnya' => $this->crawlBerikutnya(),
        ]);
    }

    /**
     * Jadwal berikutnya dibaca dari penjadwal, bukan ditulis ulang di sini.
     * Menyalin ekspresi cron-nya ke controller berarti angka di layar akan
     * berbohong begitu irama di routes/console.php diubah dan orang lupa
     * mengubah salinannya.
     *
     * routes/console.php dimuat di dalam Kernel::bootstrap(), bukan saat
     * kernelnya diresolusi. Meresolusi saja menghasilkan daftar event kosong,
     * dan layar menampilkan "tidak terjadwal" padahal jadwalnya ada. Maka
     * bootstrap() dipanggil eksplisit di sini.
     */
    private function crawlBerikutnya(): ?string
    {
        app(ConsoleKernel::class)->bootstrap();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'crawl:feeds'));

        return $event ? Carbon::instance($event->nextRunDate())->toJSON() : null;
    }

    /**
     * Menarik seluruh sumber aktif sekarang juga, tanpa menunggu jadwal.
     *
     * Dilempar ke antrean, tidak dijalankan langsung seperti tombol sisir
     * antrean AI. Bedanya nyata: menyisir antrean hanya tiga kueri di database
     * sendiri, sedangkan crawl mengunduh 28 feed dari 28 server milik orang
     * lain. Sekali jalan penuh memakan sekitar dua menit, dan permintaan HTTP
     * yang menunggu selama itu akan mati di tengah jalan oleh batas waktu
     * PHP-FPM, menyisakan crawl setengah jadi tanpa ada yang tahu.
     *
     * `--paksa` mengabaikan interval_menit, karena admin yang menekan tombol
     * ini memang sedang tidak mau menunggu. Sumber yang sudah dimatikan tetap
     * tidak ikut ditarik, penjaganya ada di CrawlFeeds.
     */
    public function crawl(): RedirectResponse
    {
        Artisan::queue('crawl:feeds', ['--paksa' => true]);

        return back()->with('sukses', 'Crawl dijalankan di latar belakang.')
            ->with('catatan', 'Menarik seluruh sumber aktif, sekitar dua menit. Muat ulang halaman ini untuk melihat hasilnya masuk satu per satu.');
    }
}
