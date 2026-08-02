<?php

namespace App\Services\Crawler;

use App\Jobs\AmbilIsiArtikel;
use App\Models\Artikel;
use App\Models\Media;
use App\Models\SumberFeed;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Menyimpan item feed sebagai baris artikel dan menjadwalkan pengambilan isinya.
 *
 * Dipakai crawl:feeds maupun crawl:google-news supaya aturan pencocokan media
 * dan deduplikasi lapis 1 hanya ada di satu tempat.
 */
class PencatatArtikel
{
    /** @var array<string, int|null> cache domain => media_id selama satu proses crawl */
    private array $cacheMedia = [];

    public function __construct(private NormalisasiUrl $normalisasi) {}

    /**
     * Baris artikel baru, atau null kalau URL-nya sudah pernah masuk.
     *
     * Deduplikasi lapis 1 ditegakkan index unique `url_kanonik`, bukan
     * pemeriksaan `exists()` lebih dulu. Dengan tiga worker crawl paralel,
     * pemeriksaan-lalu-simpan punya celah balapan; constraint database tidak.
     */
    public function catat(ItemFeed $item, ?SumberFeed $sumber = null): ?Artikel
    {
        $kanonik = $this->normalisasi->kanonik($item->url);

        try {
            $artikel = Artikel::withoutGlobalScopes()->create([
                'media_id' => $sumber?->media_id ?? $this->mediaDariDomain($kanonik),
                'sumber_feed_id' => $sumber?->id,
                'judul' => mb_substr($item->judul, 0, 500),
                'url' => mb_substr($item->url, 0, 1000),
                'url_kanonik' => mb_substr($kanonik, 0, 1000),
                'ringkasan' => $item->ringkasan,
                'penulis' => $item->penulis ? mb_substr($item->penulis, 0, 150) : null,
                'dipublikasikan_at' => $item->dipublikasikanAt,
                'diambil_at' => now(),
                'status_proses' => 'mentah',
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        AmbilIsiArtikel::dispatch($artikel->id);

        return $artikel;
    }

    /**
     * Media pemilik artikel, dicari dari domain URL.
     *
     * Kalau tidak ketemu, media_id dibiarkan null dan admin bisa menautkannya
     * nanti. Ini jalur normal untuk hasil Google News dari media di luar daftar.
     */
    private function mediaDariDomain(string $url): ?int
    {
        $domain = $this->normalisasi->domain($url);

        if ($domain === null) {
            return null;
        }

        return $this->cacheMedia[$domain] ??= Media::withoutGlobalScopes()
            ->where('domain', $domain)
            ->value('id');
    }
}
