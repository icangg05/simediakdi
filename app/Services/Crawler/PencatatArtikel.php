<?php

namespace App\Services\Crawler;

use App\Jobs\AmbilIsiArtikel;
use App\Models\Artikel;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Models\UrlDibuang;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Menyimpan item feed sebagai baris artikel dan menjadwalkan pengambilan isinya.
 *
 * Dipakai crawl:feeds maupun crawl:backfill supaya aturan pencocokan media dan
 * deduplikasi lapis 1 hanya ada di satu tempat.
 */
class PencatatArtikel
{
    /** @var array<string, int|null> cache domain => media_id selama satu proses crawl */
    private array $cacheMedia = [];

    public function __construct(private NormalisasiUrl $normalisasi) {}

    /**
     * Baris artikel baru, atau null kalau URL-nya sudah pernah masuk atau
     * sudah pernah dibuang.
     *
     * Deduplikasi lapis 1 ditegakkan index unique `url_kanonik`, bukan
     * pemeriksaan `exists()` lebih dulu. Dengan tiga worker crawl paralel,
     * pemeriksaan-lalu-simpan punya celah balapan; constraint database tidak.
     */
    public function catat(ItemFeed $item, ?SumberFeed $sumber = null): ?Artikel
    {
        $kanonik = $this->normalisasi->kanonik($item->url);

        // Nisan diperiksa lebih dulu, dan ini memang pemeriksaan biasa tanpa
        // penjaga balapan. Yang terburuk kalau dua worker menyimpan bersamaan
        // adalah satu artikel yang sudah dibuang masuk lagi, persis keadaan
        // sebelum tabel ini ada. Menaikkannya jadi constraint database berarti
        // foreign key ke tabel yang barisnya justru sengaja dihapus.
        if ($this->pernahDibuang($kanonik)) {
            return null;
        }

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
     * Sudah pernah dibuang admin, jadi tidak perlu ditawarkan lagi.
     *
     * Nisan yang cocok tidak dihitung sebagai kegagalan crawl. Dari sudut
     * pandang log ia sama dengan salinan biasa, karena memang itu maksudnya:
     * URL yang sudah pernah kita lihat dan putuskan.
     */
    private function pernahDibuang(string $kanonik): bool
    {
        return UrlDibuang::where('url_kanonik', mb_substr($kanonik, 0, 1000))->exists();
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
