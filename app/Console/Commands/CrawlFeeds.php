<?php

namespace App\Console\Commands;

use App\Enums\TipeSumber;
use App\Models\LogCrawl;
use App\Models\SumberFeed;
use App\Services\Crawler\ItemFeed;
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
        {--media= : Jalankan seluruh sumber milik satu media, berdasarkan id}
        {--paksa : Abaikan interval_menit dan jalankan meski belum jatuh tempo}';

    protected $description = 'Menarik artikel baru dari sumber RSS dan scraping yang sudah jatuh tempo';

    public function handle(
        PengunduhHalaman $pengunduh,
        PembacaRss $pembacaRss,
        PembacaScrape $pembacaScrape,
        PencatatArtikel $pencatat,
    ): int {
        $satuSumber = $this->option('sumber');
        $satuMedia = $this->option('media');

        $sumber = SumberFeed::withoutGlobalScopes()
            ->when($satuSumber, fn ($q, $id) => $q->where('id', $id))
            ->when($satuMedia, fn ($q, $id) => $q->where('media_id', $id))
            // Saringan aktif berdiri sendiri, di luar jatuhTempo(). Sebelumnya
            // ia hanya hidup di dalam scope itu, jadi `--paksa` ikut menarik
            // sumber yang sudah dimatikan karena gagal lima kali berturut-turut.
            // Tombol crawl manual memakai `--paksa`, dan satu klik akan
            // menghidupkan kembali seluruh sumber rusak yang sengaja dibungkam.
            //
            // `--sumber` tetap dikecualikan: admin yang menyebut satu id memang
            // sedang menguji sumber yang baru diperbaiki. `--media` tidak,
            // karena tombolnya menarik sekumpulan sumber sekaligus dan admin
            // tidak sedang memeriksa satu per satu.
            ->when(! $satuSumber, fn ($q) => $q->where('aktif', true))
            ->when(! $this->option('paksa') && ! $satuSumber, fn ($q) => $q->jatuhTempo())
            // Saklar induk ada di medianya, bukan di sini.
            //
            // Sebelumnya perintah ini tidak pernah melihat kolom `media.aktif`
            // sama sekali, sehingga media yang sudah dinonaktifkan admin tetap
            // ditarik selama sumber feed-nya masih hidup. Itu kebalikan dari
            // yang dijanjikan tombolnya.
            //
            // Ditulis sebagai subkueri, bukan whereHas, supaya global scope
            // MilikMedia tidak ikut terpasang. Perintah ini berjalan di konsol,
            // tempat tidak ada pengguna yang sedang masuk, dan scope itu tidak
            // punya arti di sana.
            //
            // Berlaku juga untuk `--sumber` dan `--paksa`. Keduanya jalan
            // manual, tetapi saklar yang bisa ditembus jalur manual bukan
            // saklar. Sumber tanpa media, sisa Google News yang sudah dicabut,
            // ikut tersaring karena tidak punya saklar sama sekali.
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('media')
                ->whereColumn('media.id', 'sumber_feed.media_id')
                ->where('media.aktif', true)
                ->whereNull('media.deleted_at'))
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
            // Satu tipe saja yang butuh Chromium, dan hanya untuk halaman
            // indeksnya. Isi artikelnya tetap diambil job biasa.
            $isi = $sumber->tipe === TipeSumber::ScrapeRender
                ? $pengunduh->unduhTerender($sumber->url, $sumber->selector['item'] ?? null)
                : $pengunduh->unduh($sumber->url);

            $item = $sumber->tipe->pakaiSelector()
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
                // Item yang URL-nya sudah ada di tabel artikel, ditambah item
                // yang dibuang saringan kata kunci.
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
     * Beberapa kata kunci dipisah koma, dan item lolos kalau memuat salah satu
     * di antaranya. Satu kata tidak cukup untuk media nasional: liputan yang
     * dicari kadang menyebut "Kendari", kadang hanya "Sultra", dan memaksa
     * admin memilih salah satu berarti membuang separuh yang lain.
     *
     * @param  list<ItemFeed>  $item
     * @return list<ItemFeed>
     */
    private function saringKataKunci(array $item, SumberFeed $sumber): array
    {
        $cari = array_values(array_filter(
            array_map(
                static fn (string $satu): string => mb_strtolower(trim($satu)),
                explode(',', (string) $sumber->kata_kunci),
            ),
            static fn (string $satu): bool => $satu !== '',
        ));

        if ($cari === []) {
            return $item;
        }

        return array_values(array_filter($item, static function (ItemFeed $satu) use ($cari): bool {
            $teks = mb_strtolower($satu->judul.' '.$satu->ringkasan);

            foreach ($cari as $kata) {
                if (str_contains($teks, $kata)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Kegagalan dihitung dan dilaporkan, tetapi tidak lagi mematikan sumbernya.
     *
     * F-07 dulu menonaktifkan sumber setelah lima kegagalan berturut-turut.
     * Aturan itu dicabut karena penyebab kegagalan hampir selalu ada di luar
     * kendali kita dan sembuh sendiri: origin di balik Cloudflare menjawab 525
     * selama beberapa jam, CDN media nasional menolak koneksi karena kena
     * pembatasan sesaat, atau satu judul memuat `&` telanjang yang merobohkan
     * seluruh XML. Sumber yang mati diam-diam berarti berita media itu berhenti
     * masuk tanpa ada yang menyadarinya sampai berminggu-minggu kemudian.
     *
     * Hitungannya tetap naik, dan itu yang penting. Dashboard mengurutkan
     * sumber berdasarkan `gagal_berturut` dan halaman detail media menampilkan
     * pesan galat terakhir, jadi sumber yang benar-benar rusak tetap terlihat
     * dan admin yang memutuskan kapan mematikannya lewat centang Aktif.
     */
    private function tanganiKegagalan(SumberFeed $sumber, LogCrawl $log, Throwable $e): void
    {
        $gagal = $sumber->gagal_berturut + 1;

        $sumber->update([
            'gagal_berturut' => $gagal,
            'pesan_error_terakhir' => mb_substr($e->getMessage(), 0, 1000),
        ]);

        $log->update([
            'selesai_at' => now(),
            'status' => 'gagal',
            'pesan' => mb_substr($e->getMessage(), 0, 2000),
        ]);

        $this->warn("  {$sumber->nama}: gagal ({$gagal}×), {$e->getMessage()}");
    }
}
