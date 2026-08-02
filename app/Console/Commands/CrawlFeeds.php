<?php

namespace App\Console\Commands;

use App\Enums\TipeSumber;
use App\Models\LogCrawl;
use App\Models\SumberFeed;
use App\Services\Crawler\PembacaRss;
use App\Services\Crawler\PembacaScrape;
use App\Services\Crawler\PencatatArtikel;
use App\Services\Crawler\PengunduhHalaman;
use Illuminate\Console\Command;
use Throwable;

class CrawlFeeds extends Command
{
    protected $signature = 'crawl:feeds
        {--sumber= : Jalankan satu sumber saja, berdasarkan id}
        {--paksa : Abaikan interval_menit dan jalankan meski belum jatuh tempo}';

    protected $description = 'Menarik artikel baru dari sumber RSS dan scraping yang sudah jatuh tempo';

    public function handle(
        PengunduhHalaman $pengunduh,
        PembacaRss $pembacaRss,
        PembacaScrape $pembacaScrape,
        PencatatArtikel $pencatat,
    ): int {
        $sumber = SumberFeed::withoutGlobalScopes()
            ->when($this->option('sumber'), fn ($q, $id) => $q->where('id', $id))
            ->when(! $this->option('paksa') && ! $this->option('sumber'), fn ($q) => $q->jatuhTempo())
            // Google News punya command sendiri agar jadwalnya bisa berbeda.
            ->whereIn('tipe', [TipeSumber::Rss, TipeSumber::Scrape])
            ->get();

        if ($sumber->isEmpty()) {
            $this->info('Tidak ada sumber yang jatuh tempo.');

            return self::SUCCESS;
        }

        foreach ($sumber as $satu) {
            $this->jalankan($satu, $pengunduh, $pembacaRss, $pembacaScrape, $pencatat);
        }

        return self::SUCCESS;
    }

    private function jalankan(
        SumberFeed $sumber,
        PengunduhHalaman $pengunduh,
        PembacaRss $pembacaRss,
        PembacaScrape $pembacaScrape,
        PencatatArtikel $pencatat,
    ): void {
        $log = LogCrawl::create([
            'sumber_feed_id' => $sumber->id,
            'dimulai_at' => now(),
            'jumlah_ditemukan' => 0,
            'jumlah_baru' => 0,
            'jumlah_salinan' => 0,
            'status' => 'gagal',
        ]);

        $sumber->update(['dijalankan_terakhir_at' => now()]);

        try {
            $isi = $pengunduh->unduh($sumber->url);

            $item = $sumber->tipe === TipeSumber::Scrape
                ? $pembacaScrape->baca($isi, $sumber)
                : $pembacaRss->baca($isi, $sumber->url);

            $baru = 0;

            foreach ($item as $satu) {
                if ($pencatat->catat($satu, $sumber) !== null) {
                    $baru++;
                }
            }

            $log->update([
                'selesai_at' => now(),
                'jumlah_ditemukan' => count($item),
                'jumlah_baru' => $baru,
                // Duplikat lapis 2 baru diketahui setelah isinya diunduh, jadi
                // yang tercatat di sini hanya URL yang sudah pernah masuk.
                'jumlah_salinan' => count($item) - $baru,
                'status' => count($item) === 0 ? 'sebagian' : 'sukses',
                'pesan' => count($item) === 0 ? 'Feed terbaca tapi tidak berisi item.' : null,
            ]);

            $sumber->update([
                'berhasil_terakhir_at' => now(),
                'gagal_berturut' => 0,
                'pesan_error_terakhir' => null,
            ]);

            $this->line("  {$sumber->nama}: {$baru} baru dari ".count($item).' item');
        } catch (Throwable $e) {
            $this->tanganiKegagalan($sumber, $log, $e);
        }
    }

    /** F-07: lima kegagalan berturut-turut menonaktifkan sumber. */
    private function tanganiKegagalan(SumberFeed $sumber, LogCrawl $log, Throwable $e): void
    {
        $gagal = $sumber->gagal_berturut + 1;
        $batas = (int) config('crawler.maks_gagal_berturut');

        $sumber->update([
            'gagal_berturut' => $gagal,
            'pesan_error_terakhir' => mb_substr($e->getMessage(), 0, 1000),
            'aktif' => $gagal < $batas ? $sumber->aktif : false,
        ]);

        $log->update([
            'selesai_at' => now(),
            'status' => 'gagal',
            'pesan' => mb_substr($e->getMessage(), 0, 2000),
        ]);

        $this->warn("  {$sumber->nama}: gagal ({$gagal}/{$batas}) — {$e->getMessage()}");

        if ($gagal >= $batas) {
            $this->error("  {$sumber->nama} dinonaktifkan setelah {$batas} kegagalan berturut-turut.");
        }
    }
}
