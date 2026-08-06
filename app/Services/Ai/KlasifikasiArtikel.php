<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Services\Ai\DTO\HasilKlasifikasi;

/**
 * Satu-satunya tempat hasil Gemini diterjemahkan menjadi baris database.
 *
 * Dipisah dari controller karena tiga jalur memanggilnya: tombol Klasifikasi di
 * halaman antrean, tombol yang sama di halaman arsip artikel, dan koreksi
 * relevansi manual yang meneruskan artikel ke penilaian sentimen. Menyalin
 * pemetaan kolom ke tiga tempat adalah cara paling umum ketiganya perlahan
 * berbeda, dan bedanya baru terlihat sebagai angka dashboard yang tidak bisa
 * dijelaskan.
 *
 * Dijalankan sinkron, bukan lewat antrean. Selama prompt masih disetel, hasil
 * yang muncul seketika di layar jauh lebih cepat dinilai benar atau salah
 * daripada hasil yang muncul beberapa menit kemudian. Memindahkannya ke latar
 * belakang nanti berarti membungkus kelas ini dalam satu job, bukan menulis
 * ulang logikanya.
 */
class KlasifikasiArtikel
{
    public function __construct(private GeminiClassificationService $ai) {}

    /**
     * Relevansi lalu sentimen untuk satu artikel.
     *
     * @return list<HasilKlasifikasi>
     */
    public function jalankan(Artikel $artikel): array
    {
        $baris = $this->baris($artikel);

        // Keputusan manusia adalah keputusan akhir (F-13). Artikel yang sudah
        // diputuskan dilewati tanpa memanggil relevansi sama sekali, bukan
        // dipanggil lalu hasilnya dibuang. Panggilan yang hasilnya sudah pasti
        // tidak dipakai tetap memakan kuota free tier.
        if ($baris?->relevan_manual !== null) {
            if (! $baris->relevan) {
                $artikel->update(['status_proses' => 'tidak_relevan']);

                return [];
            }

            $hasil = [$this->sentimen($artikel, $baris)];
            $artikel->update(['status_proses' => 'selesai']);

            return $hasil;
        }

        $relevansi = $this->ai->relevansi($artikel);
        $relevan = $relevansi->label === 'relevan';

        // Baris analisis dibuat untuk artikel relevan maupun tidak. Yang tidak
        // relevan tetap perlu barisnya, karena di situlah alasan artikel tidak
        // muncul di dashboard bisa ditelusuri. Menghapusnya berarti tidak ada
        // bedanya antara "dinilai tidak relevan" dan "belum pernah dinilai".
        $kolom = [
            'relevan' => $relevan,
            'provider' => $relevansi->penyedia,
            'reason_code' => $relevansi->alasanKode,
            'reason_summary' => $relevansi->alasanRingkas,
            'evidence' => $relevansi->bukti,
            'prompt_version' => $relevansi->versiPrompt,
        ];

        // Jawaban tidak relevan membatalkan sentimen yang pernah dinilai
        // sebelumnya. Tanpa ini barisnya menyimpan dua jawaban yang bertentangan
        // sekaligus, dan yang lama menang di layar: `provider` sudah berbunyi
        // gemini sementara `model_versi` masih menyebut IndoBERT dari pipeline
        // lama, sehingga halaman detail berbunyi "Dinilai gemini:
        // indobert-sentiment-classifier-2.0.0".
        if (! $relevan) {
            $kolom += AnalisisSentimen::SENTIMEN_KOSONG;
        }

        $baris = AnalisisSentimen::updateOrCreate(['artikel_id' => $artikel->id], $kolom);

        $hasil = [$relevansi];

        if ($relevan) {
            $hasil[] = $this->sentimen($artikel, $baris);
        }

        $artikel->update([
            'status_proses' => match (true) {
                $relevan => 'selesai',
                $relevansi->label === 'perlu_review' => 'perlu_review',
                default => 'tidak_relevan',
            },
        ]);

        return $hasil;
    }

    /**
     * Sentimen saja, untuk artikel yang relevansinya sudah diputuskan manusia.
     *
     * @return list<HasilKlasifikasi>
     */
    public function jalankanSentimen(Artikel $artikel): array
    {
        $baris = $this->baris($artikel);

        if ($baris === null || ! $baris->relevan) {
            return [];
        }

        $hasil = [$this->sentimen($artikel, $baris)];

        $artikel->update(['status_proses' => 'selesai']);

        return $hasil;
    }

    private function sentimen(Artikel $artikel, AnalisisSentimen $baris): HasilKlasifikasi
    {
        $hasil = $this->ai->sentimen($artikel);

        $baris->update([
            // `perlu_review` bukan label sentimen. Constraint database hanya
            // menerima negatif, netral, dan positif, dan itu memang benar:
            // ketiganya yang dijumlahkan dashboard. Keraguan model disimpan
            // sebagai label kosong ditambah penanda antrean, bukan dipaksa
            // menjadi netral. Netral berarti "sudah dinilai dan hasilnya
            // datar", bukan "belum tahu".
            'label_model' => $hasil->label === 'perlu_review' ? null : $hasil->label,
            // Koreksi manusia adalah kepastian. Baris yang sudah dikoreksi
            // tidak pernah kembali masuk antrean review hanya karena model
            // dijalankan ulang dan kali ini ragu.
            'perlu_review' => $baris->label_manual === null && $hasil->perluReview,
            'model_versi' => $hasil->model,
            'provider' => $hasil->penyedia,
            'reason_code' => $hasil->alasanKode,
            'reason_summary' => $hasil->alasanRingkas,
            'evidence' => $hasil->bukti,
            'prompt_version' => $hasil->versiPrompt,
            'dianalisis_at' => now(),
        ]);

        return $hasil;
    }

    private function baris(Artikel $artikel): ?AnalisisSentimen
    {
        return AnalisisSentimen::where('artikel_id', $artikel->id)->first();
    }
}
