<?php

namespace App\Jobs;

use App\Enums\TipeSumber;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Services\Crawler\PenemuFeed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Mencari alamat RSS satu media lalu mendaftarkannya sebagai sumber feed.
 *
 * Dilempar ke antrean, tidak dijalankan saat form disimpan. Pencariannya
 * membuka sampai delapan alamat di server orang lain, dan permintaan HTTP milik
 * admin tidak boleh menunggu selama itu.
 *
 * `feed_dicari_at` selalu diisi di akhir, berhasil maupun tidak. Kolom itulah
 * yang membedakan "masih diantrekan" dari "sudah dicari, memang tidak ada",
 * dan daftar media membaca perbedaan itu untuk memutuskan apakah admin perlu
 * diminta mengisi alamatnya sendiri.
 */
class TemukanFeedMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $mediaId)
    {
        $this->onQueue('crawl');
    }

    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(PenemuFeed $penemu): void
    {
        $media = Media::withoutGlobalScopes()->find($this->mediaId);

        if (! $media) {
            return;
        }

        // Media yang sudah punya sumber feed tidak diganggu. Admin bisa saja
        // menambahkan alamatnya sendiri sebelum pekerjaan ini sempat berjalan,
        // dan hasil tebakan tidak boleh menimpa isian tangan.
        if (SumberFeed::withoutGlobalScopes()->where('media_id', $media->id)->exists()) {
            $media->forceFill(['feed_dicari_at' => now()])->save();

            return;
        }

        $url = $penemu->cari($media);

        if ($url !== null) {
            SumberFeed::withoutGlobalScopes()->create([
                'media_id' => $media->id,
                'nama' => "RSS {$media->nama}",
                'tipe' => TipeSumber::Rss,
                'url' => $url,
                'aktif' => true,
            ]);
        }

        $media->forceFill(['feed_dicari_at' => now()])->save();
    }
}
