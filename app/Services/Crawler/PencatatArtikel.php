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
 * Dipakai crawl:feeds maupun crawl:backfill supaya aturan pencocokan media dan
 * pemeriksaan duplikat hanya ada di satu tempat.
 */
class PencatatArtikel
{
    /** @var array<string, int|null> cache domain => media_id selama satu proses crawl */
    private array $cacheMedia = [];

    public function __construct(private NormalisasiUrl $normalisasi) {}

    /**
     * Baris artikel baru, atau null kalau URL-nya sudah ada di database.
     *
     * Alurnya satu arah dan tanpa cabang: normalisasi URL, tanya database
     * apakah URL itu sudah tercatat, tolak kalau ya, simpan kalau tidak.
     */
    public function catat(ItemFeed $item, ?SumberFeed $sumber = null): ?Artikel
    {
        $kanonik = mb_substr($this->normalisasi->kanonik($item->url), 0, 1000);

        if ($this->sudahAda($kanonik)) {
            return null;
        }

        try {
            $artikel = Artikel::withoutGlobalScopes()->create([
                'media_id' => $sumber?->media_id ?? $this->mediaDariDomain($kanonik),
                'sumber_feed_id' => $sumber?->id,
                'judul' => mb_substr($item->judul, 0, 500),
                'url' => mb_substr($item->url, 0, 1000),
                'url_kanonik' => $kanonik,
                'ringkasan' => $item->ringkasan,
                'penulis' => $item->penulis ? mb_substr($item->penulis, 0, 150) : null,
                'dipublikasikan_at' => $item->dipublikasikanAt,
                'diambil_at' => now(),
                'status_proses' => 'mentah',
            ]);
        } catch (UniqueConstraintViolationException) {
            // Jaring terakhir, bukan pemeriksaan utama. Tiga worker crawl bisa
            // melewati sudahAda() pada detik yang sama untuk URL yang sama, dan
            // index unique url_kanonik yang memutuskan siapa yang menang.
            return null;
        }

        AmbilIsiArtikel::dispatch($artikel->id);

        return $artikel;
    }

    /** Sudah pernah masuk, jadi tidak perlu disimpan lagi. */
    private function sudahAda(string $kanonik): bool
    {
        return Artikel::withoutGlobalScopes()->where('url_kanonik', $kanonik)->exists();
    }

    /**
     * Media pemilik artikel, dicari dari domain URL.
     *
     * Kalau tidak ketemu, media_id dibiarkan null dan admin bisa menautkannya
     * nanti. Terjadi kalau feed memuat tautan ke media di luar daftar 30.
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
