<?php

namespace App\Console\Commands;

use App\Models\KonteksPantauan;
use App\Models\PrediksiRelevansi;
use App\Models\SampelRelevansi;
use App\Models\VersiKonteksRelevansi;
use App\Models\VersiModelRelevansi;
use App\Services\Relevance\ArtefakBerubah;
use App\Services\Relevance\KlienPrediksiRelevansi;
use App\Services\Relevance\RelevanceInputBuilder;
use App\Services\Relevance\SkorPrioritasPelabelan;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Menjalankan model relevansi atas sampel dataset, lalu menghitung ulang
 * prioritas antreannya. Dokumen 10 bagian 8 dan 19.2.
 *
 * Inilah yang menyalakan active learning. Dokumen 10 bagian 8 menyebut dua
 * belas sinyal penentu urutan pelabelan, dan sembilan di antaranya butuh
 * prediksi model. Tanpa prediksi, antrean hanya punya empat sinyal kata kunci
 * dan ribuan sampel berskor nol yang tidak bisa dibedakan satu sama lain.
 *
 * Model kandidat boleh dipakai di sini, dan itu disengaja. Yang dilarang
 * gerbang mutu adalah meneruskan artikel ke sentimen, bukan memakai model untuk
 * memilih artikel mana yang layak dilabeli manusia. Kesalahan prediksi di sini
 * tidak masuk dashboard, ia justru menjadi bahan pelabelan berikutnya.
 */
class PrediksiDatasetRelevansi extends Command
{
    protected $signature = 'relevance:repredict
        {--model= : Versi model, bawaannya kandidat terbaru}
        {--batas=0 : Berhenti setelah sekian sampel, 0 berarti semua}
        {--ulang : Prediksi ulang sampel yang sudah punya prediksi dari model ini}';

    protected $description = 'Menjalankan model relevansi atas dataset dan menghitung ulang prioritas';

    public function handle(
        KlienPrediksiRelevansi $klien,
        RelevanceInputBuilder $builder,
        SkorPrioritasPelabelan $prioritas,
    ): int {
        $model = $this->model();

        if ($model === null) {
            $this->error('Tidak ada model relevansi yang bisa dipakai. Latih satu lebih dulu.');

            return self::FAILURE;
        }

        $konteks = KonteksPantauan::utama();
        $versiKonteks = VersiKonteksRelevansi::where('status', 'active')->first();

        if ($konteks === null || $versiKonteks === null) {
            $this->error('Konteks utama atau versi konteks aktif belum ada.');

            return self::FAILURE;
        }

        $this->info("Model: {$model->versi} ({$model->status})");

        $kueri = SampelRelevansi::query()->where('is_excluded', false);

        if (! $this->option('ulang')) {
            $kueri->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                ->from('prediksi_relevansi')
                ->whereColumn('prediksi_relevansi.sampel_relevansi_id', 'sampel_relevansi.id')
                ->where('prediksi_relevansi.versi_model_relevansi_id', $model->id));
        }

        $batas = (int) $this->option('batas');
        $total = $batas > 0 ? min($kueri->count(), $batas) : $kueri->count();

        if ($total === 0) {
            $this->info('Tidak ada sampel yang perlu diprediksi.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $selesai = 0;
        $gagal = 0;
        $teksKonteks = $builder->konteks($konteks);

        try {
            $kueri->orderBy('id')->chunkById(32, function ($sampel) use (
                $klien, $builder, $prioritas, $model, $konteks, $versiKonteks,
                $teksKonteks, $bar, &$selesai, &$gagal, $total
            ) {
                if ($selesai + $gagal >= $total) {
                    return false;
                }

                $pasangan = $sampel->map(fn (SampelRelevansi $s) => [
                    'id' => $s->id,
                    'konteks' => $teksKonteks,
                    'teks' => $builder->dariSampel($s, $konteks),
                ])->all();

                try {
                    $hasil = $klien->prediksi($model, $pasangan);
                } catch (ArtefakBerubah $e) {
                    throw $e;
                } catch (Throwable $e) {
                    // Satu batch gagal tidak boleh membatalkan ribuan yang
                    // sudah berhasil. Sampel yang terlewat tetap tanpa prediksi,
                    // jadi menjalankan ulang perintah ini akan mencobanya lagi.
                    $gagal += $sampel->count();
                    $bar->advance($sampel->count());
                    $this->newLine();
                    $this->warn('Batch gagal: '.mb_substr($e->getMessage(), 0, 160));

                    return true;
                }

                $this->simpan($sampel, $hasil, $model, $versiKonteks, $teksKonteks, $builder, $konteks, $prioritas);

                $selesai += $sampel->count();
                $bar->advance($sampel->count());

                return true;
            });
        } catch (ArtefakBerubah $e) {
            $bar->finish();
            $this->newLine(2);
            $this->error($e->getMessage());
            $this->warn('Gerbang mutu harus dicabut untuk model ini. Jangan dipakai sampai artefaknya diperiksa.');

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Diprediksi: {$selesai}. Gagal: {$gagal}.");

        $this->ringkas($model);

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Prediksi tidak pernah menimpa yang lama, ia menambah baris.
     *
     * Riwayat prediksi adalah satu-satunya cara mengetahui apakah model baru
     * membaik atau justru mengalami regresi pada artikel tertentu, dan itu
     * hilang begitu barisnya ditimpa.
     *
     * @param  Collection<int, SampelRelevansi>  $sampel
     * @param  array<int, array<string, mixed>>  $hasil
     */
    private function simpan(
        $sampel,
        array $hasil,
        VersiModelRelevansi $model,
        VersiKonteksRelevansi $versiKonteks,
        string $teksKonteks,
        RelevanceInputBuilder $builder,
        KonteksPantauan $konteks,
        SkorPrioritasPelabelan $prioritas,
    ): void {
        $ambang = $model->versi_threshold_relevansi_id;
        $baris = [];

        foreach ($sampel as $satu) {
            $h = $hasil[$satu->id] ?? null;

            if ($h === null) {
                continue;
            }

            $teks = $builder->dariSampel($satu, $konteks);

            $baris[] = [
                'sampel_relevansi_id' => $satu->id,
                'artikel_id' => $satu->artikel_id,
                'versi_model_relevansi_id' => $model->id,
                'versi_threshold_relevansi_id' => $ambang,
                'versi_konteks_relevansi_id' => $versiKonteks->id,
                'label_prediksi' => $h['probabilitas_relevan'] >= 0.5 ? 'relevan' : 'tidak_relevan',
                'probabilitas_relevan' => $h['probabilitas_relevan'],
                'probabilitas_tidak_relevan' => $h['probabilitas_tidak_relevan'],
                'confidence' => max($h['probabilitas_relevan'], $h['probabilitas_tidak_relevan']),
                'review_required' => false,
                'input_hash' => $builder->inputHash($teksKonteks, $teks),
                'input_tokens' => $h['input_tokens'],
                'input_truncated' => $h['input_truncated'],
                'inference_ms' => $h['inference_ms'],
                'predicted_at' => now(),
                'created_at' => now(),
            ];

            $satu->update($prioritas->hitungDenganPrediksi($satu, $h));
        }

        if ($baris !== []) {
            PrediksiRelevansi::insert($baris);
        }
    }

    private function model(): ?VersiModelRelevansi
    {
        if ($versi = $this->option('model')) {
            return VersiModelRelevansi::where('versi', $versi)->first();
        }

        return VersiModelRelevansi::produksi()->first()
            ?? VersiModelRelevansi::where('status', 'candidate')->latest('id')->first();
    }

    private function ringkas(VersiModelRelevansi $model): void
    {
        $teratas = SampelRelevansi::belumDilabeli()
            ->orderByDesc('priority_score')
            ->limit(3)
            ->get(['id', 'judul', 'priority_score', 'priority_reasons']);

        $this->newLine();
        $this->line('Antrean pelabelan teratas sekarang:');

        foreach ($teratas as $satu) {
            $alasan = implode(', ', array_keys($satu->priority_reasons ?? []));
            $this->line("  [{$satu->priority_score}] ".mb_substr($satu->judul, 0, 60)." ({$alasan})");
        }

        $this->newLine();
        $this->line('Sampel berskor nol: '.SampelRelevansi::belumDilabeli()->where('priority_score', 0)->count());
    }
}
