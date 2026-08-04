<?php

namespace App\Console\Commands;

use App\Models\SampelRelevansi;
use Illuminate\Console\Command;

/**
 * Menyiapkan dan menghitung ronde konsistensi pelabel. Dokumen 10 bagian 11.7.
 *
 * Gold set dilabeli satu orang, jadi batas atas akurasi yang wajar diminta dari
 * model adalah konsistensi pelabel dengan dirinya sendiri. Kalau manusia hanya
 * konsisten 82%, F1 0,85 adalah hasil sangat baik dan bukan kekurangan, dan
 * tanpa angka ini tidak ada cara membedakan model yang buruk dari label yang
 * berubah-ubah.
 *
 * Dua tahap, sengaja dipisah oleh waktu:
 *
 *   relevance:konsistensi siapkan   lalu labeli ulang lewat antrean
 *   relevance:konsistensi hitung    setelah selesai
 *
 * Jeda itu bagian dari pengukurannya. Melabeli ulang di hari yang sama hanya
 * mengukur ingatan jangka pendek, bukan konsistensi aturan.
 */
class RondeKonsistensiRelevansi extends Command
{
    protected $signature = 'relevance:konsistensi
        {tahap : siapkan atau hitung}
        {--jumlah=40 : Banyak sampel yang diambil acak}
        {--seed=42 : Benih pengacakan, supaya bisa diulang}';

    protected $description = 'Menyiapkan atau menghitung ronde konsistensi pelabel';

    public function handle(): int
    {
        return match ($this->argument('tahap')) {
            'siapkan' => $this->siapkan(),
            'hitung' => $this->hitung(),
            default => $this->gagalTahap(),
        };
    }

    /**
     * Menandai sampel yang akan dilabeli ulang, tanpa menghapus label lamanya.
     *
     * Label lama disalin ke `metadata_sumber` lalu `last_reviewed_at`
     * dikosongkan supaya sampelnya muncul lagi di antrean tinjauan. Yang tidak
     * boleh terjadi: pelabel melihat jawabannya sendiri sebelum memutuskan.
     * Panel memang menampilkan label yang sudah ada, jadi ronde ini dijalankan
     * dengan kesadaran itu, dan angkanya dibaca sebagai batas atas yang
     * optimistis, bukan pengukuran buta.
     */
    private function siapkan(): int
    {
        $jumlah = (int) $this->option('jumlah');

        $sampel = SampelRelevansi::layakLatih()
            ->whereRaw("COALESCE(metadata_sumber->>'ronde_konsistensi', '') = ''")
            ->inRandomOrder((string) $this->option('seed'))
            ->limit($jumlah)
            ->get(['id', 'label_manual', 'metadata_sumber']);

        if ($sampel->count() < $jumlah) {
            $this->warn("Hanya {$sampel->count()} sampel tersedia, diminta {$jumlah}.");
        }

        foreach ($sampel as $satu) {
            $satu->update([
                'metadata_sumber' => array_merge($satu->metadata_sumber ?? [], [
                    'ronde_konsistensi' => [
                        'label_ronde_1' => $satu->label_manual->value,
                        'disiapkan_at' => now()->toIso8601String(),
                    ],
                ]),
                'last_reviewed_at' => null,
            ]);
        }

        $this->info("{$sampel->count()} sampel disiapkan untuk ronde 2.");
        $this->line('Labeli ulang lewat /admin/model-relevansi?tab=dataset&belum_direview=1');
        $this->line('Setelah selesai, jalankan: php artisan relevance:konsistensi hitung');

        return self::SUCCESS;
    }

    /**
     * Menghitung kesesuaian dan Cohen's kappa antara ronde 1 dan ronde 2.
     *
     * Kappa dipakai karena persentase kesesuaian sendirian menyesatkan pada
     * data yang timpang: pelabel yang selalu menjawab "tidak relevan" akan
     * mencetak 74% kesesuaian tanpa membaca satu artikel pun. Kappa
     * memperhitungkan kesesuaian yang bisa terjadi karena kebetulan.
     */
    private function hitung(): int
    {
        $sampel = SampelRelevansi::query()
            ->whereRaw("metadata_sumber->'ronde_konsistensi' IS NOT NULL")
            ->whereNotNull('last_reviewed_at')
            ->whereNotNull('label_manual')
            ->get(['id', 'label_manual', 'metadata_sumber']);

        if ($sampel->isEmpty()) {
            $this->error('Belum ada sampel ronde 2 yang selesai dilabeli ulang.');

            return self::FAILURE;
        }

        $matriks = ['relevan' => ['relevan' => 0, 'tidak_relevan' => 0], 'tidak_relevan' => ['relevan' => 0, 'tidak_relevan' => 0]];

        foreach ($sampel as $satu) {
            $ronde1 = $satu->metadata_sumber['ronde_konsistensi']['label_ronde_1'] ?? null;
            $ronde2 = $satu->label_manual->value;

            if ($ronde1 !== null) {
                $matriks[$ronde1][$ronde2]++;
            }
        }

        $total = $sampel->count();
        $setuju = $matriks['relevan']['relevan'] + $matriks['tidak_relevan']['tidak_relevan'];
        $persen = round($setuju / $total * 100, 1);

        $this->info("Sampel dibandingkan: {$total}");
        $this->line("Kesesuaian: {$setuju}/{$total} ({$persen}%)");
        $this->line('Cohen kappa: '.number_format($this->kappa($matriks, $total), 3));

        $this->newLine();
        $this->line('Berbeda antar ronde:');

        foreach ($sampel as $satu) {
            $ronde1 = $satu->metadata_sumber['ronde_konsistensi']['label_ronde_1'] ?? null;

            if ($ronde1 !== null && $ronde1 !== $satu->label_manual->value) {
                $this->line("  sampel {$satu->id}: {$ronde1} menjadi {$satu->label_manual->value}");
            }
        }

        $this->newLine();
        $this->warn('Kappa di bawah 0,75 berarti aturan pelabelannya yang perlu diperbaiki lebih dulu, bukan modelnya. Dokumen 05 bagian 8 nomor 1.');

        return self::SUCCESS;
    }

    /** @param array<string, array<string, int>> $matriks */
    private function kappa(array $matriks, int $total): float
    {
        $setuju = ($matriks['relevan']['relevan'] + $matriks['tidak_relevan']['tidak_relevan']) / $total;

        $ronde1Relevan = array_sum($matriks['relevan']) / $total;
        $ronde2Relevan = ($matriks['relevan']['relevan'] + $matriks['tidak_relevan']['relevan']) / $total;

        $kebetulan = $ronde1Relevan * $ronde2Relevan + (1 - $ronde1Relevan) * (1 - $ronde2Relevan);

        return $kebetulan >= 1.0 ? 1.0 : ($setuju - $kebetulan) / (1 - $kebetulan);
    }

    private function gagalTahap(): int
    {
        $this->error('Tahap harus siapkan atau hitung.');

        return self::FAILURE;
    }
}
