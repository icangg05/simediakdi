<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\KunciGemini;
use App\Support\Waktu;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\RateLimitedException;

/**
 * Menjalankan satu panggilan Gemini dengan kunci yang masih punya kuota.
 *
 * Kunci yang kena limit ditandai beserta waktu pulihnya, lalu dilewati sampai
 * waktu itu tiba. Tanpa penandaan itu, rotasi hanya memindahkan masalah:
 * setiap artikel berikutnya mencoba kunci yang sama, kena 429 yang sama, dan
 * membayar satu permintaan gagal sebelum pindah ke kunci berikutnya.
 *
 * Kuncinya disuntikkan lewat config lalu instance provider dibuang, karena
 * Laravel AI SDK membaca kunci saat provider di-resolve dan menyimpan hasilnya
 * di manager. Tanpa `forgetInstance`, kunci kedua tidak pernah terpakai.
 *
 * Tidak ada jalan pintas tanpa kunci. `.env` sudah tidak memuat GEMINI_API_KEY,
 * jadi tabel kosong berarti sistem memang belum bisa memanggil Gemini, dan
 * galat yang menyebutkan itu jauh lebih berguna daripada 401 dari Google.
 */
class RotasiKunciGemini
{
    /**
     * Zona waktu reset kuota harian Gemini.
     *
     * Google menghitung kuota harian free tier per hari kalender waktu Pasifik
     * dan mengembalikannya pada tengah malam waktu itu, bukan pada 24 jam
     * setelah permintaan pertama dan bukan pada tengah malam waktu lokal.
     * Menebak WITA membuat kunci ditahan sampai 15 jam lebih lama dari perlu,
     * atau dicoba lagi terlalu cepat dan kembali kena 429.
     */
    private const ZONA_KUOTA = 'America/Los_Angeles';

    /**
     * Jeda terpendek untuk limit per menit.
     *
     * Google mengirim `retryDelay` pada sebagian galat, tetapi tidak selalu.
     * Tanpa batas bawah, kunci yang kena limit per menit langsung dicoba lagi
     * pada artikel berikutnya dan gagal lagi.
     */
    private const JEDA_MINIMAL_DETIK = 60;

    /**
     * @template T
     *
     * @param  Closure(): T  $panggil
     * @return T
     *
     * @throws RateLimitedException
     */
    public function jalankan(Closure $panggil): mixed
    {
        $terakhir = null;

        foreach ($this->giliran() as $kunci) {
            $this->pakai($kunci);

            try {
                return $panggil();
            } catch (RateLimitedException $galat) {
                $this->tandaiLimit($kunci, $galat);
                $terakhir = $galat;
            }
        }

        throw $terakhir ?? $this->semuaHabis();
    }

    /**
     * Urutan pemakaian: yang paling lama menganggur lebih dulu.
     *
     * @return Collection<int, KunciGemini>
     */
    private function giliran()
    {
        return KunciGemini::query()
            ->tersedia()
            ->orderByRaw('terakhir_dipakai_at ASC NULLS FIRST')
            ->orderBy('id')
            ->get();
    }

    private function pakai(KunciGemini $kunci): void
    {
        config(['ai.providers.gemini.key' => $kunci->kunci]);

        Ai::forgetInstance('gemini');

        $kunci->forceFill(['terakhir_dipakai_at' => now()])->saveQuietly();
    }

    private function tandaiLimit(KunciGemini $kunci, RateLimitedException $galat): void
    {
        [$sampai, $alasan] = $this->waktuPulih($galat);

        $kunci->forceFill([
            'limit_sampai' => $sampai,
            'alasan_limit' => $alasan,
        ])->saveQuietly();
    }

    /**
     * Waktu pulih dibaca dari badan galat Google, bukan ditebak.
     *
     * Badan 429 memuat `QuotaFailure` berisi `quotaId` yang menyebut apakah
     * yang jebol kuota per menit atau per hari, dan sering memuat `RetryInfo`
     * berisi `retryDelay`. Keduanya jauh lebih tepat daripada satu angka mati,
     * dan bedanya besar: menit versus sisa hari.
     *
     * @return array{Carbon, string}
     */
    private function waktuPulih(RateLimitedException $galat): array
    {
        $harian = false;
        $jeda = null;

        foreach ($this->rincian($galat) as $rincian) {
            foreach ((array) ($rincian['violations'] ?? []) as $pelanggaran) {
                $penanda = strtolower(
                    ($pelanggaran['quotaId'] ?? '').' '.($pelanggaran['quotaMetric'] ?? '')
                );

                if (str_contains($penanda, 'perday') || str_contains($penanda, 'per_day')) {
                    $harian = true;
                }
            }

            if (isset($rincian['retryDelay'])) {
                $jeda = (int) rtrim((string) $rincian['retryDelay'], 's');
            }
        }

        // Dikembalikan sebagai UTC. Kolom timestamptz dikirimi jam dinding
        // tanpa offset (lihat config/app.php), jadi Carbon yang masih berzona
        // Pasifik akan tersimpan meleset beberapa jam.
        if ($harian) {
            return [Carbon::tomorrow(self::ZONA_KUOTA)->utc(), 'kuota_harian'];
        }

        return [
            now()->addSeconds(max($jeda ?? 0, self::JEDA_MINIMAL_DETIK)),
            $jeda !== null ? 'retry_delay' : 'kuota_menit',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function rincian(RateLimitedException $galat): array
    {
        $asal = $galat->getPrevious();

        if (! $asal instanceof RequestException || $asal->response === null) {
            return [];
        }

        return (array) $asal->response->json('error.details', []);
    }

    private function semuaHabis(): RateLimitedException
    {
        $pulih = KunciGemini::query()
            ->where('aktif', true)
            ->whereNotNull('limit_sampai')
            ->orderBy('limit_sampai')
            ->value('limit_sampai');

        $pesan = $pulih === null
            ? 'Tidak ada kunci API Gemini yang aktif. Tambahkan kuncinya di halaman Pengaturan.'
            : 'Semua kunci API Gemini sedang kena limit. Kuota berikutnya terbuka '
                .Carbon::parse($pulih)->timezone(Waktu::ZONA)->translatedFormat('j F Y H:i').' WITA.';

        return new RateLimitedException($pesan, 429);
    }
}
