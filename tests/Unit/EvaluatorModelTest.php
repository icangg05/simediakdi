<?php

namespace Tests\Unit;

use App\Services\Nlp\EvaluatorModel;
use PHPUnit\Framework\TestCase;

/**
 * Angka F1 inilah yang menentukan sistem dipercaya atau tidak dalam rapat, dan
 * salah hitung di sini tidak akan terlihat dari mana pun.
 */
class EvaluatorModelTest extends TestCase
{
    private EvaluatorModel $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new EvaluatorModel;
    }

    public function test_prediksi_sempurna_menghasilkan_f1_satu(): void
    {
        $hasil = $this->evaluator->hitung([
            ['gold' => 'negatif', 'prediksi' => 'negatif'],
            ['gold' => 'netral', 'prediksi' => 'netral'],
            ['gold' => 'positif', 'prediksi' => 'positif'],
        ]);

        $this->assertSame(1.0, $hasil['akurasi']);
        $this->assertSame(1.0, $hasil['f1_macro']);
    }

    /**
     * Kasus yang jadi alasan macro dipakai: model yang tidak pernah mengenali
     * berita positif. Rata-rata berbobot akan menyembunyikannya karena kelas
     * positif memang jarang muncul.
     */
    public function test_kelas_yang_tidak_pernah_dikenali_menjatuhkan_f1_macro(): void
    {
        $pasangan = [];

        for ($i = 0; $i < 9; $i++) {
            $pasangan[] = ['gold' => 'negatif', 'prediksi' => 'negatif'];
        }

        $pasangan[] = ['gold' => 'positif', 'prediksi' => 'negatif'];

        $hasil = $this->evaluator->hitung($pasangan);

        $this->assertSame(0.9, $hasil['akurasi']);
        $this->assertSame(0.0, $hasil['f1_positif'], 'Kelas positif tidak pernah benar sekali pun.');
        // Akurasi 90% tapi F1 macro jauh di bawahnya, itu justru gunanya.
        $this->assertLessThan(0.65, $hasil['f1_macro']);
    }

    public function test_confusion_matrix_memetakan_gold_ke_prediksi(): void
    {
        $hasil = $this->evaluator->hitung([
            ['gold' => 'negatif', 'prediksi' => 'netral'],
            ['gold' => 'negatif', 'prediksi' => 'netral'],
            ['gold' => 'netral', 'prediksi' => 'netral'],
        ]);

        // Baris = gold, kolom = prediksi. Terbalik berarti confusion matrix
        // di halaman evaluasi menuduh kelas yang salah.
        $this->assertSame(2, $hasil['confusion_matrix']['negatif']['netral']);
        $this->assertSame(0, $hasil['confusion_matrix']['netral']['negatif']);
        $this->assertSame(1, $hasil['confusion_matrix']['netral']['netral']);
    }

    public function test_f1_dihitung_dari_presisi_dan_recall_bukan_akurasi(): void
    {
        // netral: TP=1, diprediksi 3 kali (presisi 1/3), sebenarnya 1 (recall 1)
        // F1 = 2 * (1/3 * 1) / (1/3 + 1) = 0,5
        $hasil = $this->evaluator->hitung([
            ['gold' => 'netral', 'prediksi' => 'netral'],
            ['gold' => 'negatif', 'prediksi' => 'netral'],
            ['gold' => 'positif', 'prediksi' => 'netral'],
        ]);

        $this->assertSame(0.5, $hasil['f1_netral']);
    }

    public function test_gold_set_kosong_tidak_membuat_pembagian_nol(): void
    {
        $hasil = $this->evaluator->hitung([]);

        $this->assertSame(0, $hasil['jumlah_sampel']);
        $this->assertSame(0.0, $hasil['akurasi']);
        $this->assertSame(0.0, $hasil['f1_macro']);
    }
}
