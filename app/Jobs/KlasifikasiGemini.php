<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AntreanGemini;
use App\Services\Ai\KlasifikasiArtikel;
use App\Services\Ai\RotasiKunciGemini;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

/**
 * Menilai satu artikel dengan Gemini di latar belakang.
 *
 * Isinya sengaja tipis. Seluruh logika penilaian tetap di KlasifikasiArtikel,
 * kelas yang sama dengan yang dipakai tombol Klasifikasi di layar. Menyalinnya
 * ke sini akan membuat hasil antrean dan hasil tombol perlahan berbeda, dan
 * bedanya baru terlihat sebagai angka dashboard yang tidak bisa dijelaskan.
 *
 * Yang ditambahkan job ini hanya dua: mencatat kemajuannya ke tabel antrean,
 * dan menunda dirinya sendiri saat kuota habis alih-alih menembak Gemini lalu
 * ditolak.
 */
class KlasifikasiGemini implements ShouldQueue
{
    use Queueable;

    /**
     * Tanpa batas percobaan di tingkat Laravel, dibatasi waktu saja.
     *
     * Penundaan karena kuota habis ikut dihitung sebagai percobaan oleh Laravel,
     * dan kuota harian baru pulih tengah malam waktu Pasifik. Batas percobaan
     * berapa pun yang masuk akal untuk kegagalan nyata akan terlalu kecil untuk
     * penungguan yang wajar. Batas percobaan yang sebenarnya dijaga kolom
     * `percobaan` di tabel antrean, dan itu hanya bertambah saat benar-benar
     * gagal.
     */
    public int $tries = 0;

    public int $timeout = 300;

    /**
     * Batas atas penundaan sendiri saat kuota habis.
     *
     * Job ini dulu tidur selama sisa waktu sampai kuota harian pulih, dan itu
     * bisa tujuh jam. Selama tidur, barisnya tetap terhitung sebagai pekerjaan
     * menggantung oleh `gemini:antre`, jadi dua puluh job yang tidur memenuhi
     * seluruh jatah gantung dan tidak ada satu pun pekerjaan baru yang dilepas.
     * Antreannya membeku total.
     *
     * Yang membuatnya buruk bukan lamanya, melainkan tidurnya tidak bisa
     * dibangunkan. Admin yang menambahkan kunci baru berkuota penuh tetap
     * menatap antrean diam sampai lewat tengah malam waktu Pasifik, sementara
     * tombol Klasifikasi di layar bekerja normal dengan kunci yang sama.
     *
     * Lima menit membuat job memeriksa ulang keadaan secara berkala. Satu
     * pemeriksaan hanya dua kueri murah, dan kunci baru mulai terpakai paling
     * lama lima menit setelah ditambahkan.
     */
    private const JEDA_TUNDA_MAKS = 300;

    public function __construct(public int $antreanId)
    {
        $this->onQueue('gemini');
    }

    /** Dibuang setelah sehari penuh. Lebih lama dari itu artinya memang macet. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(26);
    }

    public function handle(KlasifikasiArtikel $klasifikasi, RotasiKunciGemini $rotasi): void
    {
        $baris = AntreanGemini::with('artikel')->find($this->antreanId);

        // Barisnya hilang atau sudah dikerjakan orang lain, misalnya lewat
        // tombol Klasifikasi di layar sementara job ini masih mengantre.
        if ($baris === null || $baris->status === 'selesai') {
            return;
        }

        // Kuota habis. Ditunda, bukan dijalankan lalu ditolak: permintaan yang
        // dijawab 429 tetap dihitung Google sebagai permintaan, jadi mencoba
        // terus justru memperpanjang masa tunggunya sendiri.
        if (($tunggu = $rotasi->tungguDetik()) > 0) {
            $this->tunda($baris, $tunggu);

            return;
        }

        $artikel = $baris->artikel;

        if ($artikel === null) {
            $baris->delete();

            return;
        }

        if ($artikel->isi === null || trim($artikel->isi) === '') {
            // Bukan gangguan sesaat, jadi percobaannya dihabiskan sekaligus.
            // Mengulanginya tidak akan memunculkan isi yang memang tidak ada.
            $baris->update([
                'status' => 'gagal',
                'percobaan' => AntreanGemini::MAKS_PERCOBAAN,
                'galat' => 'Artikel belum punya isi, jadi tidak ada yang bisa dinilai.',
                'selesai_at' => now(),
            ]);

            return;
        }

        $baris->update(['status' => 'berjalan', 'dimulai_at' => now(), 'galat' => null]);

        try {
            $klasifikasi->jalankan($artikel);
        } catch (RateLimitedException $galat) {
            // Kuota, bukan kerusakan. Pemeriksaan di awal job hanya melihat
            // keadaan sebelum pekerjaan dimulai, sedangkan batas bisa terlampaui
            // di tengah jalan, misalnya karena worker lain memakai kunci yang
            // sama pada menit yang sama.
            //
            // Jatah percobaan sengaja tidak dikurangi. Kuota harian yang habis
            // pada sore hari akan menghabiskan ketiga jatah setiap artikel
            // sebelum tengah malam, dan esok paginya antrean terlihat kosong
            // bukan karena selesai melainkan karena semuanya sudah menyerah.
            report($galat);

            // Minimal semenit meski kuncinya sudah terlihat siap lagi. Batas
            // yang baru saja terlampaui hampir selalu batas per menit, dan
            // mencoba lagi seketika hanya menambah satu permintaan yang
            // dijawab 429 lalu tetap dihitung Google.
            $this->tunda($baris, max(60, $rotasi->tungguDetik()));

            return;
        } catch (Throwable $galat) {
            report($galat);

            $baris->update([
                'status' => 'gagal',
                'percobaan' => $baris->percobaan + 1,
                'galat' => mb_substr($galat->getMessage(), 0, 500),
                'selesai_at' => now(),
            ]);

            return;
        }

        $baris->update(['status' => 'selesai', 'galat' => null, 'selesai_at' => now()]);
    }

    /**
     * Menidurkan pekerjaan ini sebentar, lalu memeriksa keadaan lagi.
     *
     * Waktu tidurnya dan `dijadwalkan_at` dihitung dari satu angka yang sama.
     * Kalau keduanya berbeda, halaman pemantauan menyebut jam bangun yang tidak
     * pernah terjadi, dan jatah gantung dilepas pada saat yang salah.
     */
    private function tunda(AntreanGemini $baris, int $tunggu): void
    {
        $tunggu = min(max($tunggu, 1), self::JEDA_TUNDA_MAKS);

        $baris->update([
            'status' => 'menunggu',
            'dijadwalkan_at' => now()->addSeconds($tunggu),
            'galat' => 'Menunggu kuota Gemini terbuka kembali.',
        ]);

        $this->release($tunggu);
    }

    /**
     * Dipanggil saat job mati tanpa sempat menangani galatnya sendiri, misalnya
     * karena melewati timeout. Tanpa ini barisnya tertinggal berstatus berjalan
     * selamanya dan halaman pemantauan menunjukkan pekerjaan hantu.
     */
    public function failed(?Throwable $galat): void
    {
        $baris = AntreanGemini::find($this->antreanId);

        $baris?->update([
            'status' => 'gagal',
            'percobaan' => $baris->percobaan + 1,
            'galat' => mb_substr($galat?->getMessage() ?? 'Pekerjaan berhenti tanpa keterangan.', 0, 500),
            'selesai_at' => now(),
        ]);
    }
}
