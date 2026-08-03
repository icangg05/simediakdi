<?php

namespace App\Console\Commands;

use App\Models\Artikel;
use App\Models\Media;
use App\Services\Crawler\ArsipWordPress;
use App\Services\Crawler\NormalisasiUrl;
use App\Services\Crawler\PenyelesaiArtikel;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Menarik arsip lama dari media WordPress untuk membangun korpus.
 *
 * Bukan bagian dari operasi harian, crawler RSS yang mengurus berita baru.
 * Perintah ini dijalankan sekali saat gold set butuh lebih banyak artikel
 * daripada yang bisa dikumpulkan feed dalam waktu wajar. Kelas negatif hanya
 * sekitar 5% dari pasangan relevan, jadi mengukur F1 negatif butuh ribuan
 * artikel, sedangkan RSS hanya memberi sepuluh sampai lima puluh terbaru.
 */
class CrawlBackfill extends Command
{
    protected $signature = 'crawl:backfill
        {--media= : Batasi ke satu media, berdasarkan slug}
        {--halaman=4 : Berapa halaman arsip per media, 50 tulisan per halaman}
        {--mulai=1 : Halaman awal, untuk melanjutkan penarikan yang terputus}';

    protected $description = 'Menarik arsip lama lewat WP REST untuk memperbesar korpus gold set';

    public function handle(
        ArsipWordPress $arsip,
        PenyelesaiArtikel $penyelesai,
        NormalisasiUrl $normalisasi,
    ): int {
        $media = Media::withoutGlobalScopes()
            ->where('aktif', true)
            ->whereNotNull('domain')
            ->when($this->option('media'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('nama')
            ->get();

        if ($media->isEmpty()) {
            $this->error('Tidak ada media yang cocok.');

            return self::FAILURE;
        }

        $maks = (int) $this->option('halaman');
        $mulai = (int) $this->option('mulai');
        $totalBaru = 0;

        foreach ($media as $satu) {
            $baru = $this->tarik($satu, $arsip, $penyelesai, $normalisasi, $mulai, $maks);
            $totalBaru += $baru;
        }

        $this->newLine();
        $this->info("{$totalBaru} artikel baru masuk. Jalankan worker antrean nlp untuk menganalisisnya:");
        $this->line('  php artisan queue:work redis --queue=nlp --stop-when-empty');

        return self::SUCCESS;
    }

    private function tarik(
        Media $media,
        ArsipWordPress $arsip,
        PenyelesaiArtikel $penyelesai,
        NormalisasiUrl $normalisasi,
        int $mulai,
        int $maks,
    ): int {
        $baru = 0;
        $sudahAda = 0;

        for ($halaman = $mulai; $halaman < $mulai + $maks; $halaman++) {
            $pos = $arsip->halaman($media->domain, $halaman);

            if ($pos === null) {
                break;
            }

            foreach ($pos as $satu) {
                $kanonik = $normalisasi->kanonik($satu['item']->url);

                try {
                    // Dibungkus transaksi bersarang, yang di PostgreSQL menjadi
                    // SAVEPOINT. Tanpa itu, satu pelanggaran unique meracuni
                    // seluruh transaksi berjalan dan setiap perintah berikutnya
                    // ditolak, penarikan berhenti di URL duplikat pertama.
                    $artikel = DB::transaction(fn () => Artikel::withoutGlobalScopes()->create([
                        'media_id' => $media->id,
                        'judul' => mb_substr($satu['item']->judul, 0, 500),
                        'url' => mb_substr($satu['item']->url, 0, 1000),
                        'url_kanonik' => mb_substr($kanonik, 0, 1000),
                        'penulis' => $satu['item']->penulis ? mb_substr($satu['item']->penulis, 0, 150) : null,
                        'dipublikasikan_at' => $satu['item']->dipublikasikanAt,
                        // Waktu sistem mengambilnya, bukan waktu terbit. Grafik
                        // harian memakai kolom ini, jadi arsip lama akan
                        // menumpuk di hari penarikan, itu memang apa adanya.
                        'diambil_at' => now(),
                        'status_proses' => 'mentah',
                    ]));
                } catch (UniqueConstraintViolationException) {
                    // Sudah pernah masuk lewat RSS. Deduplikasi lapis 1.
                    $sudahAda++;

                    continue;
                }

                // Isi sudah ada di tangan, jadi AmbilIsiArtikel dilewati,
                // itulah seluruh keuntungan backfill. Sisanya diproses sama
                // persis dengan jalur crawl biasa.
                $penyelesai->selesaikan($artikel, $satu['hasil']);
                $baru++;
            }
        }

        $this->line(sprintf('  %-24s %4d baru, %4d sudah ada', mb_substr($media->nama, 0, 23), $baru, $sudahAda));

        return $baru;
    }
}
