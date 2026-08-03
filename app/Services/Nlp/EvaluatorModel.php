<?php

namespace App\Services\Nlp;

use App\Models\GoldSet;
use App\Models\KonteksPantauan;

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
     * pelabel, menilai nada artikel yang memang tidak membahas konteksnya
     * tidak mengukur apa pun.
     *
     * @return list<array{gold: string, prediksi: string}>
     */
    public function pasanganDariGoldSet(int $ronde = 1, ?int $konteksId = null): array
    {
        return GoldSet::query()
            ->where('gold_set.ronde', $ronde)
            ->where('gold_set.relevan_gold', true)
            ->when($konteksId, fn ($q) => $q->where('gold_set.konteks_pantauan_id', $konteksId))
            ->join('analisis_sentimen', function ($join) {
                $join->on('analisis_sentimen.artikel_id', '=', 'gold_set.artikel_id')
                    ->on('analisis_sentimen.konteks_pantauan_id', '=', 'gold_set.konteks_pantauan_id');
            })
            // label_model, bukan label_efektif: yang diukur kemampuan model,
            // dan label_efektif sudah memuat koreksi manusia, memakainya
            // berarti mengukur model terhadap jawaban yang sebagian ditulis
            // manusia sendiri, lalu melaporkan akurasi yang terlalu bagus.
            ->whereNotNull('analisis_sentimen.label_model')
            ->get(['gold_set.label_gold as gold', 'analisis_sentimen.label_model as prediksi'])
            ->map(fn ($baris) => ['gold' => $baris->gold, 'prediksi' => $baris->prediksi])
            ->all();
    }

    /**
     * Metrik penyaring relevansi.
     *
     * Sama pentingnya dengan metrik sentimen dan sempat terlewat: seluruh
     * alasan relevansi dijalankan sebelum sentimen adalah menjaga agar artikel
     * yang tidak membahas konteks tidak ikut mengotori grafik (dokumen 02
     * bagian 5). Kalau presisinya rendah, dashboard terisi artikel yang
     * seharusnya tidak ada di sana, dan tanpa angka ini, tidak ada yang tahu.
     *
     * Recall lebih penting daripada presisi di sini. Artikel relevan yang
     * terbuang hilang selamanya dari analisis; artikel tidak relevan yang lolos
     * masih bisa dikoreksi admin lewat halaman detail.
     *
     * @return array<string, mixed>|null
     */
    public function metrikRelevansi(int $ronde = 1, ?int $konteksId = null): ?array
    {
        $baris = GoldSet::query()
            ->where('gold_set.ronde', $ronde)
            ->when($konteksId, fn ($q) => $q->where('gold_set.konteks_pantauan_id', $konteksId))
            ->join('analisis_sentimen', function ($join) {
                $join->on('analisis_sentimen.artikel_id', '=', 'gold_set.artikel_id')
                    ->on('analisis_sentimen.konteks_pantauan_id', '=', 'gold_set.konteks_pantauan_id');
            })
            ->get(['gold_set.relevan_gold as gold', 'analisis_sentimen.relevan as prediksi']);

        if ($baris->isEmpty()) {
            return null;
        }

        $tp = $baris->where('gold', true)->where('prediksi', true)->count();
        $fp = $baris->where('gold', false)->where('prediksi', true)->count();
        $fn = $baris->where('gold', true)->where('prediksi', false)->count();
        $tn = $baris->where('gold', false)->where('prediksi', false)->count();

        $presisi = $tp + $fp === 0 ? 0.0 : $tp / ($tp + $fp);
        $recall = $tp + $fn === 0 ? 0.0 : $tp / ($tp + $fn);

        return [
            'jumlah_sampel' => $baris->count(),
            'benar_relevan' => $tp,
            'salah_dianggap_relevan' => $fp,
            'relevan_yang_terlewat' => $fn,
            'benar_tidak_relevan' => $tn,
            'presisi' => round($presisi, 4),
            'recall' => round($recall, 4),
            'f1' => $presisi + $recall === 0.0 ? 0.0 : round(2 * $presisi * $recall / ($presisi + $recall), 4),
            'akurasi' => round(($tp + $tn) / $baris->count(), 4),
        ];
    }

    /**
     * Metrik dipecah per konteks.
     *
     * Angka gabungan menyembunyikan selisih yang penting: presisi relevansi
     * terukur 87,7% pada satu konteks dan 51,1% pada konteks lain, sementara
     * gabungannya 63,2%, tidak menggambarkan keduanya. Penyebabnya daftar kata
     * kunci: frasa spesifik seperti "wali kota kendari" jarang muncul kebetulan,
     * sedangkan kata umum seperti "dinas", "pasar", atau "sampah" sering muncul
     * sambil lalu. Rincian ini yang menunjukkan konteks mana kata kuncinya perlu
     * diperketat.
     *
     * @return list<array<string, mixed>>
     */
    public function metrikPerKonteks(int $ronde = 1): array
    {
        return KonteksPantauan::query()
            ->orderBy('urutan')
            ->get(['id', 'nama'])
            ->map(function ($konteks) use ($ronde) {
                $sentimen = $this->hitung($this->pasanganDariGoldSet($ronde, $konteks->id));
                $relevansi = $this->metrikRelevansi($ronde, $konteks->id);

                // Jumlah sampel per kelas gold. F1 macro merata-ratakan ketiga
                // kelas dengan bobot sama, jadi kelas yang tidak punya sampel
                // sama sekali menghasilkan F1 nol dan menyeret rata-ratanya,
                // membuat konteks berakurasi 78% terlihat seperti 0,34. Tanpa
                // angka ini, pembacanya tidak punya cara tahu.
                $perKelas = [];

                foreach (self::KELAS as $kelas) {
                    $perKelas[$kelas] = array_sum($sentimen['confusion_matrix'][$kelas]);
                }

                return [
                    'konteks' => $konteks->nama,
                    'sampel_sentimen' => $sentimen['jumlah_sampel'],
                    'sampel_per_kelas' => $perKelas,
                    'kelas_tanpa_sampel' => array_keys(array_filter($perKelas, fn (int $n) => $n === 0)),
                    'akurasi' => $sentimen['akurasi'],
                    'f1_macro' => $sentimen['f1_macro'],
                    'presisi_relevansi' => $relevansi['presisi'] ?? null,
                    'recall_relevansi' => $relevansi['recall'] ?? null,
                ];
            })
            ->filter(fn (array $baris) => $baris['sampel_sentimen'] > 0)
            ->values()
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
