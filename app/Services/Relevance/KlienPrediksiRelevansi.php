<?php

namespace App\Services\Relevance;

use App\Models\VersiModelRelevansi;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Memanggil inferensi relevansi di layanan NLP. Dokumen 10 bagian 19.2.
 *
 * Dipisah dari `KlienNlp` karena kelompok endpoint ini berbeda sifatnya:
 * ia memakai rahasia internal, menunjuk artefak lewat path, dan punya satu
 * kegagalan yang harus dibedakan dari kegagalan biasa, yaitu checksum artefak
 * yang tidak cocok.
 */
class KlienPrediksiRelevansi
{
    /**
     * Batas per permintaan. Batch besar tidak membuat CPU lebih cepat, ia hanya
     * menahan seluruh tensor di memori sekaligus bersama model 1,3 GB.
     */
    private const MAKS_BATCH = 32;

    public function __construct(private RelevanceInputBuilder $builder) {}

    /**
     * @param  list<array{id: int, konteks: string, teks: string}>  $pasangan
     * @return array<int, array<string, mixed>> hasil dikunci id sampel
     */
    public function prediksi(VersiModelRelevansi $model, array $pasangan): array
    {
        if ($pasangan === []) {
            return [];
        }

        if ($model->artifact_path === null) {
            throw new RuntimeException("Model {$model->versi} tidak punya artefak.");
        }

        $hasil = [];

        foreach (array_chunk($pasangan, self::MAKS_BATCH) as $potongan) {
            foreach ($this->kirim($model, $potongan) as $satu) {
                $hasil[$satu['id']] = $satu;
            }
        }

        return $hasil;
    }

    /**
     * @param  list<array{id: int, konteks: string, teks: string}>  $potongan
     * @return list<array<string, mixed>>
     */
    private function kirim(VersiModelRelevansi $model, array $potongan): array
    {
        $tanggapan = Http::withHeaders($this->header())
            ->timeout((int) config('relevance.inferensi_timeout'))
            ->post($this->url('/relevancy/predict'), [
                'model_version' => $model->versi,
                'artifact_path' => $model->artifact_path,
                'max_length' => (int) ($model->runtime_info['max_length'] ?? config('relevance.preset.max_length')),
                'pasangan' => $potongan,
            ]);

        if ($tanggapan->status() === 409) {
            // Checksum artefak berubah. Bukan kegagalan sementara yang pantas
            // di-retry: bobot model tidak lagi sama dengan yang dievaluasi, dan
            // dokumen 10 bagian 12.6 menyebutnya sebab pencabutan gerbang mutu.
            throw new ArtefakBerubah($tanggapan->json('detail') ?? 'Checksum artefak tidak cocok.');
        }

        if (! $tanggapan->successful()) {
            throw new RuntimeException('Layanan prediksi menolak: '.$tanggapan->body());
        }

        return $tanggapan->json('hasil', []);
    }

    /** @return array<string, string> */
    private function header(): array
    {
        $rahasia = config('relevance.training_secret');

        return $rahasia ? ['X-Internal-Secret' => $rahasia] : [];
    }

    private function url(string $path): string
    {
        return rtrim(config('nlp.base_url'), '/').$path;
    }
}
