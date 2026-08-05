<?php

namespace App\Jobs;

use App\Models\AnalisisSentimen as BarisAnalisis;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Models\PrediksiRelevansi;
use App\Models\VersiKonteksRelevansi;
use App\Models\VersiModelRelevansi;
use App\Services\Relevance\ArtefakBerubah;
use App\Services\Relevance\KlienPrediksiRelevansi;
use App\Services\Relevance\RelevanceInputBuilder;
use App\Services\Relevance\RelevanceQualityGateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * F-10: menilai relevansi artikel sebelum menilai sentimen.
 *
 * Urutan ini bukan selera. Model sentimen tetap mengeluarkan label untuk
 * artikel yang tidak relevan, label itu masuk agregasi, lalu grafik dashboard
 * terisi angka yang tidak ada hubungannya dengan Pemkot Kendari. Saringan
 * relevansi membuangnya lebih dulu.
 *
 * Sejak revisi 1.6 penilainya berupa classifier hasil fine-tuning dengan dataset
 * lokal. Selama belum ada model produksi yang lolos gerbang mutu, job ini tidak
 * menebak-nebak: artikel ditandai `model_belum_lulus_gate` dan menunggu.
 * Menahan artikel adalah keputusan yang bisa dibatalkan, sedangkan angka salah
 * yang sudah masuk dashboard dan dibaca pimpinan tidak bisa ditarik kembali.
 *
 * Dokumen 05 bagian 2 dan 7, dokumen 10 bagian 1.1 dan 21.
 */
class AnalisisRelevansi implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $artikelId)
    {
        $this->onQueue('nlp');
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(
        RelevanceQualityGateService $gerbang,
        KlienPrediksiRelevansi $klien,
        RelevanceInputBuilder $builder,
    ): void {
        $artikel = Artikel::withoutGlobalScopes()->find($this->artikelId);

        if ($artikel === null || $artikel->isi === null) {
            return;
        }

        // Artikel tetap dikumpulkan dan tetap masuk dataset untuk dilabeli.
        // Yang berhenti hanya penilaian otomatis dan segala yang mengikutinya.
        if (! $gerbang->lolos()) {
            $artikel->update(['status_proses' => 'model_belum_lulus_gate']);

            return;
        }

        $model = $gerbang->modelProduksi();
        $versiKonteks = VersiKonteksRelevansi::where('status', 'active')->first();

        if ($model === null || $versiKonteks === null) {
            throw new RuntimeException('Gerbang lolos tetapi model produksi atau versi konteks aktif tidak ditemukan.');
        }

        $konteks = KonteksPantauan::where('aktif', true)->get();

        if ($konteks->isEmpty()) {
            // Tidak ada yang perlu dinilai relevansinya. Bukan kegagalan:
            // artikel selesai diproses, hanya tidak menghasilkan baris analisis.
            $artikel->update(['status_proses' => 'selesai']);

            return;
        }

        $this->nilai($artikel, $konteks, $model, $versiKonteks, $klien, $builder);
    }

    /**
     * Menilai satu artikel terhadap seluruh konteks aktif.
     *
     * Satu panggilan untuk semua konteks, bukan satu per konteks. `id` pada
     * pasangan membawa `konteks_pantauan_id` karena artikelnya sudah tetap satu,
     * pola yang sama dipakai AnalisisSentimen.
     *
     * @param  \Illuminate\Support\Collection<int, KonteksPantauan>  $konteks
     */
    private function nilai(
        Artikel $artikel,
        $konteks,
        VersiModelRelevansi $model,
        VersiKonteksRelevansi $versiKonteks,
        KlienPrediksiRelevansi $klien,
        RelevanceInputBuilder $builder,
    ): void {
        $ambang = $model->ambang;

        if ($ambang === null) {
            throw new RuntimeException("Model produksi {$model->versi} tidak menunjuk versi ambang.");
        }

        $pasangan = $konteks->map(fn (KonteksPantauan $k) => [
            'id' => $k->id,
            'konteks' => $builder->konteks($k),
            'teks' => $builder->artikel($artikel->judul, $artikel->excerpt, $artikel->isi, null, null, $k),
        ])->all();

        try {
            $hasil = $klien->prediksi($model, $pasangan);
        } catch (ArtefakBerubah $e) {
            // Bobot model tidak lagi sama dengan yang dievaluasi gerbang mutu.
            // Mengulang tidak akan menolong, dan meneruskan artikel ke sentimen
            // dengan model yang berubah diam-diam persis yang dilarang dokumen
            // 10 bagian 12.6.
            $artikel->update(['status_proses' => 'gagal']);

            throw $e;
        }

        $adaRelevan = false;
        $adaPitaReview = false;

        foreach ($konteks as $satu) {
            $h = $hasil[$satu->id] ?? null;

            if ($h === null) {
                continue;
            }

            $peluang = (float) $h['probabilitas_relevan'];
            $relevan = $peluang >= $ambang->relevant_threshold;
            $perluReview = $peluang >= $ambang->review_lower_bound && $peluang <= $ambang->review_upper_bound;

            $this->simpanPrediksi($artikel, $satu, $model, $ambang->id, $versiKonteks->id, $h, $builder, $perluReview);

            // Baris analisis dibuat untuk seluruh konteks, relevan maupun tidak.
            // Yang tidak relevan tetap perlu barisnya, karena di situlah alasan
            // artikel tidak muncul di dashboard bisa ditelusuri. Menghapusnya
            // berarti tidak ada bedanya antara "dinilai tidak relevan" dan
            // "belum pernah dinilai".
            BarisAnalisis::updateOrCreate(
                ['artikel_id' => $artikel->id, 'konteks_pantauan_id' => $satu->id],
                [
                    'relevan' => $relevan,
                    'skor_relevansi' => $peluang,
                    'versi_model_relevansi_id' => $model->id,
                    'versi_threshold_relevansi_id' => $ambang->id,
                ],
            );

            $adaRelevan = $adaRelevan || $relevan;
            $adaPitaReview = $adaPitaReview || $perluReview;
        }

        if ($adaRelevan) {
            $artikel->update(['status_proses' => 'dianalisis']);

            AnalisisSentimen::dispatch($artikel->id);

            return;
        }

        // Tidak ada satu pun konteks yang relevan. Artikel berhenti di sini,
        // dan itu jawaban akhir, bukan kegagalan. Yang skornya jatuh di pita
        // review masuk antrean manusia alih-alih dibuang diam-diam.
        $artikel->update(['status_proses' => $adaPitaReview ? 'perlu_review' : 'tidak_relevan']);
    }

    /**
     * Prediksi tidak pernah ditimpa, ia menambah baris.
     *
     * Riwayat prediksi adalah satu-satunya cara mengetahui apakah model baru
     * membaik atau justru mengalami regresi pada artikel tertentu, dan itu
     * hilang begitu barisnya ditimpa. Pola yang sama dipakai relevance:repredict.
     *
     * @param  array<string, mixed>  $h
     */
    private function simpanPrediksi(
        Artikel $artikel,
        KonteksPantauan $konteks,
        VersiModelRelevansi $model,
        int $ambangId,
        int $versiKonteksId,
        array $h,
        RelevanceInputBuilder $builder,
        bool $perluReview,
    ): void {
        $teks = $builder->artikel($artikel->judul, $artikel->excerpt, $artikel->isi, null, null, $konteks);

        PrediksiRelevansi::create([
            'artikel_id' => $artikel->id,
            'versi_model_relevansi_id' => $model->id,
            'versi_threshold_relevansi_id' => $ambangId,
            'versi_konteks_relevansi_id' => $versiKonteksId,
            'label_prediksi' => $h['probabilitas_relevan'] >= 0.5 ? 'relevan' : 'tidak_relevan',
            'probabilitas_relevan' => $h['probabilitas_relevan'],
            'probabilitas_tidak_relevan' => $h['probabilitas_tidak_relevan'],
            'confidence' => max($h['probabilitas_relevan'], $h['probabilitas_tidak_relevan']),
            'review_required' => $perluReview,
            'input_hash' => $builder->inputHash($builder->konteks($konteks), $teks),
            'input_tokens' => $h['input_tokens'] ?? null,
            'input_truncated' => $h['input_truncated'] ?? false,
            'inference_ms' => $h['inference_ms'] ?? null,
            'predicted_at' => now(),
        ]);
    }
}
