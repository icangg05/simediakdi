<?php

namespace App\Jobs;

use App\Models\Pemuatan;
use App\Services\Arsip\PenangkapLayar;
use App\Services\Crawler\EkstraktorArtikel;
use App\Services\Crawler\EkstraktorWordPress;
use App\Services\Crawler\GagalMengunduh;
use App\Services\Crawler\PengunduhHalaman;
use App\Services\Crawler\UrlDitolak;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Mengambil bukti permanen saat laporan pemuatan dikonfirmasi (F-52).
 *
 * Alasannya bukan kerapian arsip. Media bisa menghapus atau menyunting artikel
 * setelah pembayaran cair, dan saat audit datang setahun kemudian tautannya
 * mati. Teks dan tangkapan layar yang diambil sistem sendiri, dengan waktu
 * tercatat, adalah bukti yang tidak bergantung pada itikad media.
 *
 * Dua bukti diambil dan keduanya berdiri sendiri:
 *
 * 1. `arsip_teks` dari ekstraksi. Ini yang paling penting. Isinya bisa
 *    dibandingkan kata per kata dengan halaman yang diperkarakan.
 * 2. `arsip_screenshot_path` dari Playwright. Berguna untuk ditunjukkan ke
 *    orang yang tidak akan membaca teks mentah, misalnya di rapat audit.
 *
 * Kegagalan salah satunya tidak membatalkan yang lain, dan tidak satu pun
 * menghalangi verifikasi (F-51). Bukti separuh jauh lebih baik daripada baris
 * yang gagal diproses.
 */
class ArsipkanBuktiPemuatan implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $pemuatanId)
    {
        $this->onQueue('crawl');
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        PengunduhHalaman $pengunduh,
        EkstraktorWordPress $wordpress,
        EkstraktorArtikel $ekstraktor,
        PenangkapLayar $penangkap,
    ): void {
        $pemuatan = Pemuatan::withoutGlobalScopes()->find($this->pemuatanId);

        if ($pemuatan === null || $pemuatan->arsip_diambil_at !== null) {
            return;
        }

        $teks = null;

        try {
            $hasil = $ekstraktor->ekstrak($pengunduh->unduh($pemuatan->url), $pemuatan->url);

            if ($hasil->terlaluPendek() || $hasil->isi === null) {
                $hasil = $wordpress->ekstrak($pemuatan->url) ?? $hasil;
            }

            $teks = $hasil->isi;
        } catch (UrlDitolak|GagalMengunduh) {
            // Halaman sudah mati atau menolak diambil. Justru kasus inilah
            // yang paling perlu tercatat waktunya, jadi baris tetap ditandai
            // sudah diarsipkan dengan teks kosong.
        }

        $gambar = $penangkap->tangkap($pemuatan->url);
        $jalur = null;

        if ($gambar !== null) {
            $jalur = config('arsip.folder').'/'.$pemuatan->id.'-'.now()->format('Ymd-His').'.png';
            Storage::disk((string) config('arsip.disk'))->put($jalur, $gambar);
        }

        $pemuatan->forceFill([
            'arsip_teks' => $teks,
            'arsip_screenshot_path' => $jalur,
            'arsip_diambil_at' => now(),
            'status_ekstraksi' => $teks !== null ? 'berhasil' : 'gagal',
        ])->save();
    }
}
