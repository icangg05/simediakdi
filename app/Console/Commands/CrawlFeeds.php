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

            $ditemukan = count($item);
            $item = $this->saringKataKunci($item, $sumber);
            $baru = 0;

            foreach ($item as $satu) {
                if ($pencatat->catat($satu, $sumber) !== null) {
                    $baru++;
                }
            }

            $disaring = $ditemukan - count($item);

            $log->update([
                'selesai_at' => now(),
                'jumlah_ditemukan' => $ditemukan,
                'jumlah_baru' => $baru,
                // Duplikat lapis 2 baru diketahui setelah isinya diunduh, jadi
                // yang tercatat di sini hanya URL yang sudah pernah masuk,
                // ditambah item yang dibuang saringan kata kunci.
                'jumlah_salinan' => $ditemukan - $baru,
                'status' => $ditemukan === 0 ? 'sebagian' : 'sukses',
                'pesan' => match (true) {
                    $ditemukan === 0 => 'Feed terbaca tapi tidak berisi item.',
                    $disaring > 0 => "{$disaring} item dibuang saringan kata kunci \"{$sumber->kata_kunci}\".",
                    default => null,
                },
            ]);

            $sumber->update([
                'berhasil_terakhir_at' => now(),
                'gagal_berturut' => 0,
                'pesan_error_terakhir' => null,
            ]);

            $this->line("  {$sumber->nama}: {$baru} baru dari {$ditemukan} item"
                .($disaring > 0 ? " ({$disaring} disaring kata kunci)" : ''));
        } catch (Throwable $e) {
            $this->tanganiKegagalan($sumber, $log, $e);
        }
    }

    /**
     * Saringan kata kunci sebelum artikel disimpan.
     *
     * Dipakai untuk media nasional: feed utuh Tempo dan Detik didominasi berita
     * di luar Kendari dan akan menenggelamkan angka volume (dokumen 01 lampiran
     * A catatan 1). Menyaring dari judul dan ringkasan feed, bukan dari isi
     * halaman, mengunduh seratus artikel nasional untuk membuang sembilan
     * puluh delapan justru banjir yang mau dihindari.
     *
     * Sumber tanpa kata kunci tidak disaring sama sekali.
     *
     * @param  list<\App\Services\Crawler\ItemFeed>  $item
     * @return list<\App\Services\Crawler\ItemFeed>
     */
    private function saringKataKunci(array $item, SumberFeed $sumber): array
    {
        $kataKunci = trim((string) $sumber->kata_kunci);

        if ($kataKunci === '') {
            return $item;
        }

        $cari = mb_strtolower($kataKunci);

        return array_values(array_filter(
            $item,
            fn ($satu) => str_contains(mb_strtolower($satu->judul.' '.$satu->ringkasan), $cari),
        ));
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

        $this->warn("  {$sumber->nama}: gagal ({$gagal}/{$batas}), {$e->getMessage()}");

        if ($gagal >= $batas) {
            $this->error("  {$sumber->nama} dinonaktifkan setelah {$batas} kegagalan berturut-turut.");
        }
    }
}
