<?php

namespace App\Console\Commands;

use App\Jobs\AnalisisRelevansi;
use App\Models\Artikel;
use App\Services\Relevance\RelevanceQualityGateService;
use Illuminate\Console\Command;

/**
 * Mengantre ulang artikel yang tertahan karena belum ada model produksi.
 *
 * Selama gerbang mutu memblokir, `AnalisisRelevansi` menandai artikel
 * `model_belum_lulus_gate` lalu berhenti. Artikel itu tidak hilang dan tidak
 * dianalisis, ia menunggu. Perintah ini yang membangunkannya setelah model
 * dipromosikan.
 *
 * `isi_diambil` ikut disapu secara bawaan. Itu artikel yang isinya sudah ada
 * tetapi rantai jobnya putus di tengah, biasanya karena worker mati saat
 * antrean panjang, dan tanpa disapu ia diam selamanya tanpa masuk hitungan
 * kegagalan mana pun.
 */
class ProsesArtikelTertahan extends Command
{
    protected $signature = 'relevance:proses-tertahan
        {--status=* : Status yang disapu, bawaannya model_belum_lulus_gate dan isi_diambil}
        {--batas=0 : Berhenti setelah sekian artikel, 0 berarti semua}';

    protected $description = 'Mengantre ulang analisis relevansi untuk artikel yang tertahan';

    public function handle(RelevanceQualityGateService $gerbang): int
    {
        if (! $gerbang->lolos()) {
            $this->error('Gerbang mutu masih memblokir. '.$gerbang->alasan());
            $this->line('Mengantre sekarang hanya menandai ulang artikel yang sama, jadi dihentikan.');

            return self::FAILURE;
        }

        $status = $this->option('status') ?: ['model_belum_lulus_gate', 'isi_diambil'];

        $kueri = Artikel::withoutGlobalScopes()
            ->whereIn('status_proses', $status)
            ->whereNotNull('isi');

        $batas = (int) $this->option('batas');
        $total = $batas > 0 ? min($kueri->count(), $batas) : $kueri->count();

        if ($total === 0) {
            $this->info('Tidak ada artikel tertahan.');

            return self::SUCCESS;
        }

        $model = $gerbang->modelProduksi();

        $this->info("Model produksi: {$model->versi}, ambang {$model->ambang?->relevant_threshold}.");
        $this->info("Mengantre {$total} artikel.");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $antre = 0;

        $kueri->orderBy('id')->chunkById(200, function ($artikel) use ($bar, &$antre, $total) {
            foreach ($artikel as $satu) {
                if ($antre >= $total) {
                    return false;
                }

                AnalisisRelevansi::dispatch($satu->id);

                $antre++;
                $bar->advance();
            }

            return true;
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("{$antre} artikel diantre ke queue nlp.");
        $this->line('Pantau dengan: docker compose logs -f worker');

        return self::SUCCESS;
    }
}
