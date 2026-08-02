<?php

namespace App\Services\Nlp;

use App\Enums\LabelSentimen;
use App\Models\GoldSet;

/**
 * Membandingkan label model terhadap gold set berlabel manusia (F-19).
 *
 * Tanpa angka ini, dashboard sentimen bisa dibantah siapa pun dan sistem
 * kehilangan kredibilitasnya dalam satu rapat. Karena itu perhitungannya
 * dibuat terpisah dari command, supaya bisa diuji tanpa menyentuh model.
 */
class EvaluatorModel
{
    /** @var list<string> urutan tetap: dipakai sebagai sumbu confusion matrix */
    public const KELAS = ['negatif', 'netral', 'positif'];

    /**
     * @param  iterable<array{gold: string, prediksi: string}>  $pasangan
     * @return array<string, mixed>
     */
    public function hitung(iterable $pasangan): array
    {
        $matriks = $this->matriksKosong();
        $jumlah = 0;
        $benar = 0;

        foreach ($pasangan as $satu) {
            $gold = $satu['gold'];
            $prediksi = $satu['prediksi'];

            if (! isset($matriks[$gold][$prediksi])) {
                continue;
            }

            $matriks[$gold][$prediksi]++;
            $jumlah++;
            $benar += (int) ($gold === $prediksi);
        }

        $f1 = [];

        foreach (self::KELAS as $kelas) {
            $f1[$kelas] = $this->f1Kelas($matriks, $kelas);
        }

        return [
            'jumlah_sampel' => $jumlah,
            'akurasi' => $jumlah === 0 ? 0.0 : round($benar / $jumlah, 4),
            // Macro, bukan weighted: kelas positif paling jarang muncul di
            // berita, dan rata-rata berbobot akan menyembunyikan model yang
            // sebenarnya tidak pernah mengenali berita positif.
            'f1_macro' => round(array_sum($f1) / count($f1), 4),
            'f1_negatif' => $f1['negatif'],
            'f1_netral' => $f1['netral'],
            'f1_positif' => $f1['positif'],
            'confusion_matrix' => $matriks,
        ];
    }

    /**
     * Pasangan gold dan prediksi dari tabel, hanya baris yang relevan menurut
     * pelabel — menilai nada artikel yang memang tidak membahas konteksnya
     * tidak mengukur apa pun.
     *
     * @return list<array{gold: string, prediksi: string}>
     */
    public function pasanganDariGoldSet(int $ronde = 1): array
    {
        return GoldSet::query()
            ->where('ronde', $ronde)
            ->where('relevan_gold', true)
            ->join('analisis_sentimen', function ($join) {
                $join->on('analisis_sentimen.artikel_id', '=', 'gold_set.artikel_id')
                    ->on('analisis_sentimen.konteks_pantauan_id', '=', 'gold_set.konteks_pantauan_id');
            })
            // label_model, bukan label_efektif: yang diukur kemampuan model,
            // dan label_efektif sudah memuat koreksi manusia — memakainya
            // berarti mengukur model terhadap jawaban yang sebagian ditulis
            // manusia sendiri, lalu melaporkan akurasi yang terlalu bagus.
            ->whereNotNull('analisis_sentimen.label_model')
            ->get(['gold_set.label_gold as gold', 'analisis_sentimen.label_model as prediksi'])
            ->map(fn ($baris) => ['gold' => $baris->gold, 'prediksi' => $baris->prediksi])
            ->all();
    }

    /**
     * Kesesuaian antara ronde 1 dan ronde 2 pada pelabel yang sama.
     *
     * Ini batas atas akurasi yang wajar diharapkan dari model: kalau manusia
     * saja hanya konsisten 80% dengan dirinya sendiri, menuntut model melebihi
     * itu tidak masuk akal.
     */
    public function konsistensiPelabel(): ?float
    {
        $ronde1 = GoldSet::where('ronde', 1)->get()->keyBy(
            fn (GoldSet $g) => $g->artikel_id.'-'.$g->konteks_pantauan_id,
        );

        $ronde2 = GoldSet::where('ronde', 2)->get();

        if ($ronde2->isEmpty()) {
            return null;
        }

        $cocok = 0;
        $total = 0;

        foreach ($ronde2 as $baris) {
            $pasangan = $ronde1->get($baris->artikel_id.'-'.$baris->konteks_pantauan_id);

            if ($pasangan === null) {
                continue;
            }

            $total++;
            $cocok += (int) ($pasangan->label_gold === $baris->label_gold);
        }

        return $total === 0 ? null : round($cocok / $total, 4);
    }

    /** @param array<string, array<string, int>> $matriks */
    private function f1Kelas(array $matriks, string $kelas): float
    {
        $tp = $matriks[$kelas][$kelas];

        $diprediksi = 0;
        foreach (self::KELAS as $gold) {
            $diprediksi += $matriks[$gold][$kelas];
        }

        $sebenarnya = array_sum($matriks[$kelas]);

        $presisi = $diprediksi === 0 ? 0.0 : $tp / $diprediksi;
        $recall = $sebenarnya === 0 ? 0.0 : $tp / $sebenarnya;

        return $presisi + $recall === 0.0
            ? 0.0
            : round(2 * $presisi * $recall / ($presisi + $recall), 4);
    }

    /** @return array<string, array<string, int>> baris = gold, kolom = prediksi */
    private function matriksKosong(): array
    {
        $matriks = [];

        foreach (self::KELAS as $gold) {
            foreach (self::KELAS as $prediksi) {
                $matriks[$gold][$prediksi] = 0;
            }
        }

        return $matriks;
    }
}
