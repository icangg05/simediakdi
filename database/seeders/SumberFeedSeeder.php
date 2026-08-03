<?php

namespace Database\Seeders;

use App\Models\Media;
use App\Models\SumberFeed;
use Illuminate\Database\Seeder;

/**
 * Daftar sumber feed hasil pengujian sprint 0 terhadap 30 media lampiran A.
 *
 * Disimpan sebagai seeder karena ini data yang paling menyakitkan kalau hilang
 * dan paling mudah diselamatkan (dokumen 02 bagian 10). Menguji ulang 30 situs
 * satu per satu memakan waktu; memulihkannya dari sini butuh satu perintah.
 *
 * Hasil pengujian: 27 dari 30 punya feed hidup.
 */
class SumberFeedSeeder extends Seeder
{
    /**
     * slug media => jalur feed. Hampir semuanya `/feed` bawaan WordPress.
     *
     * @var array<string, string>
     */
    private const FEED = [
        'sultra-tv' => '/feed',
        'sultra-demo' => '/feed',
        'kendari-pos' => '/feed',
        'radar-kendari' => '/feed',
        'kolom-rakyat' => '/feed',
        'trijaya-kendari' => '/feed',
        // Bukan /feed seperti yang lain, itu menjawab 404.
        'telisik' => '/feed/rss',
        'kendari-info' => '/feed',
        'britakita' => '/feed',
        'perdetik-news' => '/feed',
        'galeri-sultra' => '/feed',
        'radar-sultra' => '/feed',
        'figur-sultra' => '/feed',
        'lensa-timur' => '/feed',
        'koran-headline' => '/feed',
        'mediatama-sultra' => '/feed',
        'kisahan' => '/feed',
        'sultranesia' => '/feed',
        'tajuk-info' => '/feed',
        'teras-sultra' => '/feed',
        'lontara-sultra' => '/feed',
        'sultra-merdeka' => '/feed',
        'metro-kendari' => '/feed',
        'informasi-sultra' => '/feed',
        'kongkrit-post' => '/feed',
        'mitra-nusantara' => '/feed',
    ];

    /**
     * Media nasional: feed utuhnya didominasi berita di luar Kendari, jadi
     * disaring kata kunci sebelum artikel disimpan (dokumen 01 lampiran A
     * catatan 1). Domainnya berbeda dari kolom `media.domain` karena feed-nya
     * ada di subdomain tersendiri.
     *
     * @var array<string, array{url: string, kata_kunci: string}>
     */
    private const FEED_NASIONAL = [
        'tempo' => ['url' => 'https://rss.tempo.co/nasional', 'kata_kunci' => 'Kendari'],
        'detikcom' => ['url' => 'https://news.detik.com/rss', 'kata_kunci' => 'Kendari'],
    ];

    /**
     * Media tanpa feed yang bisa dipakai, beserta alasannya. Dicatat di kolom
     * `catatan` supaya tidak diuji ulang berkali-kali tanpa perlu.
     *
     * @var array<string, string>
     */
    private const TANPA_FEED = [
        'sibernas' => 'Tidak ditemukan feed pada jalur lazim maupun tautan di halaman depan. Uji ulang berkala, atau andalkan portal pelaporan.',
    ];

    /**
     * Nasional: feed utuhnya didominasi berita di luar Kendari dan akan
     * menenggelamkan angka volume (dokumen 01 lampiran A catatan 1).
     *
     * @var list<string>
     */
    private const NASIONAL_JANGAN_FEED_UTUH = ['portal-id'];

    public function run(): void
    {
        foreach (self::FEED as $slug => $jalur) {
            $media = Media::withoutGlobalScopes()->where('slug', $slug)->first();

            if ($media === null) {
                $this->command?->warn("Media {$slug} tidak ada, sumber feed dilewati.");

                continue;
            }

            SumberFeed::updateOrCreate(
                ['url' => "https://{$media->domain}{$jalur}"],
                [
                    'media_id' => $media->id,
                    'nama' => "{$media->nama} RSS",
                    'tipe' => 'rss',
                    'interval_menit' => 30,
                    'aktif' => true,
                ],
            );
        }

        foreach (self::FEED_NASIONAL as $slug => $konfigurasi) {
            $media = Media::withoutGlobalScopes()->where('slug', $slug)->first();

            if ($media === null) {
                continue;
            }

            SumberFeed::updateOrCreate(
                ['url' => $konfigurasi['url']],
                [
                    'media_id' => $media->id,
                    'nama' => "{$media->nama} RSS (disaring \"{$konfigurasi['kata_kunci']}\")",
                    'tipe' => 'rss',
                    // Dipakai CrawlFeeds untuk membuang item yang tidak menyebut
                    // Kendari sebelum artikelnya diunduh.
                    'kata_kunci' => $konfigurasi['kata_kunci'],
                    'interval_menit' => 60,
                    'aktif' => true,
                ],
            );

            $media->update([
                'catatan' => 'Nasional. Feed utuh disaring kata kunci "'.$konfigurasi['kata_kunci']
                    .'" sebelum artikel disimpan, agar berita di luar Kendari tidak menenggelamkan angka volume.',
            ]);
        }

        foreach ([...array_keys(self::TANPA_FEED), ...self::NASIONAL_JANGAN_FEED_UTUH] as $slug) {
            Media::withoutGlobalScopes()->where('slug', $slug)->update([
                'catatan' => self::TANPA_FEED[$slug] ?? 'Nasional. Jangan tarik feed utuh, pakai Google News berkata kunci Kendari.',
            ]);
        }

        // F-05: menjangkau media di luar daftar, sekaligus menangkap liputan
        // Kendari di media nasional tanpa menarik feed utuhnya.
        foreach (['Kendari', 'Pemkot Kendari', 'Wali Kota Kendari'] as $kataKunci) {
            SumberFeed::updateOrCreate(
                ['url' => 'https://news.google.com/rss/search?q='.urlencode($kataKunci)],
                [
                    'media_id' => null,
                    'nama' => "Google News: {$kataKunci}",
                    'tipe' => 'google_news',
                    'kata_kunci' => $kataKunci,
                    'interval_menit' => 60,
                    'aktif' => true,
                ],
            );
        }

        $this->command?->info(
            SumberFeed::withoutGlobalScopes()->count().' sumber feed terdaftar, '
            .count(self::TANPA_FEED).' media tanpa feed dicatat.'
        );
    }
}
