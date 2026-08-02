<?php

namespace App\Console\Commands;

use App\Enums\TipeSumber;
use App\Models\LogCrawl;
use App\Models\SumberFeed;
use App\Services\Crawler\PembacaRss;
use App\Services\Crawler\PencatatArtikel;
use App\Services\Crawler\PengunduhHalaman;
use Illuminate\Console\Command;
use Throwable;

/**
 * F-05: menangkap media di luar daftar 30 partner, dan menjangkau media
 * nasional yang feed utuhnya terlalu berisik untuk ditarik langsung.
 */
class CrawlGoogleNews extends Command
{
    protected $signature = 'crawl:google-news {--sumber= : Jalankan satu sumber saja, berdasarkan id}';

    protected $description = 'Menarik hasil Google News RSS untuk kata kunci yang dikonfigurasi';

    public function handle(PengunduhHalaman $pengunduh, PembacaRss $pembaca, PencatatArtikel $pencatat): int
    {
        $sumber = SumberFeed::withoutGlobalScopes()
            ->where('tipe', TipeSumber::GoogleNews)
            ->where('aktif', true)
            ->when($this->option('sumber'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        foreach ($sumber as $satu) {
            $log = LogCrawl::create([
                'sumber_feed_id' => $satu->id,
                'dimulai_at' => now(),
                'jumlah_ditemukan' => 0,
                'jumlah_baru' => 0,
                'jumlah_salinan' => 0,
                'status' => 'gagal',
            ]);

            $satu->update(['dijalankan_terakhir_at' => now()]);

            try {
                $item = $pembaca->baca($pengunduh->unduh($this->urlFeed($satu)), $satu->url);

                $baru = 0;

                foreach ($item as $entri) {
                    // media_id sengaja dibiarkan dicari dari domain: satu sumber
                    // Google News membawa artikel dari banyak media sekaligus.
                    if ($pencatat->catat($entri) !== null) {
                        $baru++;
                    }
                }

                $log->update([
                    'selesai_at' => now(),
                    'jumlah_ditemukan' => count($item),
                    'jumlah_baru' => $baru,
                    'jumlah_salinan' => count($item) - $baru,
                    'status' => 'sukses',
                ]);

                $satu->update(['berhasil_terakhir_at' => now(), 'gagal_berturut' => 0, 'pesan_error_terakhir' => null]);

                $this->line("  {$satu->nama}: {$baru} baru dari ".count($item).' item');
            } catch (Throwable $e) {
                $gagal = $satu->gagal_berturut + 1;
                $batas = (int) config('crawler.maks_gagal_berturut');

                $satu->update([
                    'gagal_berturut' => $gagal,
                    'pesan_error_terakhir' => mb_substr($e->getMessage(), 0, 1000),
                    'aktif' => $gagal < $batas,
                ]);

                $log->update(['selesai_at' => now(), 'status' => 'gagal', 'pesan' => mb_substr($e->getMessage(), 0, 2000)]);

                $this->warn("  {$satu->nama}: gagal ({$gagal}/{$batas}) — {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Kolom `url` boleh diisi URL Google News lengkap oleh admin. Kalau yang
     * diisi hanya domain, URL feed dirakit dari kata kuncinya.
     */
    private function urlFeed(SumberFeed $sumber): string
    {
        if (str_contains($sumber->url, 'news.google.com')) {
            return $sumber->url;
        }

        return 'https://news.google.com/rss/search?'.http_build_query([
            'q' => $sumber->kata_kunci,
            'hl' => 'id',
            'gl' => 'ID',
            'ceid' => 'ID:id',
        ]);
    }
}
