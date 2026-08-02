<?php

namespace App\Console\Commands;

use App\Models\EvaluasiModel as BarisEvaluasi;
use App\Services\Nlp\EvaluatorModel;
use Illuminate\Console\Command;

class EvaluasiModel extends Command
{
    protected $signature = 'evaluasi:model
        {--ronde=1 : Ronde gold set yang dipakai}
        {--catatan= : Catatan yang disimpan bersama hasil}';

    protected $description = 'Menjalankan gold set terhadap label model dan menyimpan metrik akurasinya';

    /** Gerbang dokumen 07: di bawah ini, jangan bangun dashboard di atasnya. */
    private const AMBANG_F1_MACRO = 0.65;

    public function handle(EvaluatorModel $evaluator): int
    {
        $ronde = (int) $this->option('ronde');
        $pasangan = $evaluator->pasanganDariGoldSet($ronde);

        if ($pasangan === []) {
            $this->error('Gold set ronde '.$ronde.' belum punya baris yang bisa dievaluasi.');
            $this->line('Labeli lebih dulu di /admin/pelabelan, lalu pastikan artikelnya sudah dianalisis model.');

            return self::FAILURE;
        }

        $metrik = $evaluator->hitung($pasangan);

        $versi = \App\Models\AnalisisSentimen::query()
            ->whereNotNull('model_versi')
            ->latest('dianalisis_at')
            ->value('model_versi') ?? 'tidak diketahui';

        BarisEvaluasi::create([
            ...$metrik,
            'model_versi' => $versi,
            'dievaluasi_at' => now(),
            'ambang_keyakinan' => (float) config('nlp.ambang.sentimen'),
            'catatan' => $this->option('catatan'),
        ]);

        $this->tampilkan($metrik, $versi, $evaluator->konsistensiPelabel());
        $this->tampilkanRelevansi($evaluator->metrikRelevansi($ronde));

        if ($metrik['f1_macro'] < self::AMBANG_F1_MACRO) {
            $this->newLine();
            $this->error('F1 macro di bawah '.self::AMBANG_F1_MACRO.'. Jangan lanjut membangun dashboard di atas');
            $this->error('model ini — angkanya akan dibantah di rapat pertama. Lihat dokumen 07.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $metrik */
    private function tampilkan(array $metrik, string $versi, ?float $konsistensi): void
    {
        $this->info("Model {$versi}, {$metrik['jumlah_sampel']} sampel");

        $this->table(['Metrik', 'Nilai'], [
            ['Akurasi', $this->persen($metrik['akurasi'])],
            ['F1 macro', $metrik['f1_macro']],
            ['F1 negatif', $metrik['f1_negatif']],
            ['F1 netral', $metrik['f1_netral']],
            ['F1 positif', $metrik['f1_positif']],
        ]);

        $this->line('Confusion matrix (baris = gold, kolom = prediksi):');

        $baris = [];
        foreach (EvaluatorModel::KELAS as $gold) {
            $baris[] = [$gold, ...array_values($metrik['confusion_matrix'][$gold])];
        }

        $this->table(['gold \\ prediksi', ...EvaluatorModel::KELAS], $baris);

        if ($konsistensi !== null) {
            $this->line('Konsistensi pelabel antar ronde: '.$this->persen($konsistensi));
            $this->line('Ini batas atas yang wajar diharapkan dari model.');
        }
    }

    /** @param array<string, mixed>|null $relevansi */
    private function tampilkanRelevansi(?array $relevansi): void
    {
        if ($relevansi === null) {
            return;
        }

        $this->newLine();
        $this->info("Penyaring relevansi, {$relevansi['jumlah_sampel']} sampel");

        $this->table(['Metrik', 'Nilai'], [
            ['Presisi', $this->persen($relevansi['presisi']).'  (dari yang disebut relevan, berapa yang benar)'],
            ['Recall', $this->persen($relevansi['recall']).'  (dari yang benar relevan, berapa yang tertangkap)'],
            ['F1', $relevansi['f1']],
            ['Salah dianggap relevan', $relevansi['salah_dianggap_relevan'].'  ← ikut mengotori grafik'],
            ['Relevan yang terlewat', $relevansi['relevan_yang_terlewat'].'  ← hilang dari analisis'],
        ]);

        if ($relevansi['presisi'] < 0.7) {
            $this->warn('Presisi relevansi di bawah 70%: sebagian artikel di dashboard sebenarnya tidak');
            $this->warn('membahas konteksnya. Angka volume ikut menggelembung. Lihat dokumen 08.');
        }
    }

    private function persen(float $nilai): string
    {
        return number_format($nilai * 100, 1).'%';
    }
}
