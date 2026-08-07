<?php

declare(strict_types=1);

namespace App\Services\ModelRelevansi;

use App\Models\PelatihanModelRelevansi;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Menjalankan satu teks pada model yang sudah dilatih.
 *
 * Isinya tipis dan memang harus begitu. Inferensi IndoBERT terjadi di layanan
 * Python, dan yang tersisa untuk PHP hanya dua hal: memastikan artefaknya masih
 * ada di disk sebelum menembak layanan, dan menyebut nama modelnya di dalam
 * pesan galat. Layanan Python tidak tahu model itu bernama apa di layar admin,
 * jadi pesannya berbunyi tentang direktori, bukan tentang model yang barusan
 * dipilih pengguna.
 */
class PrediktorRelevansi
{
    public function __construct(private LayananRelevansi $layanan) {}

    /**
     * @return array{label: string, probabilitas_relevan: float, probabilitas_tidak_relevan: float, confidence: float, inferensi_ms: int}
     */
    public function jalankan(PelatihanModelRelevansi $model, string $teks): array
    {
        if ($model->artefak_path === null || ! Storage::disk('local')->exists($model->artefak_path.'/manifest.json')) {
            throw new RuntimeException(
                "Berkas model {$model->nama} tidak ditemukan. Model perlu dilatih ulang."
            );
        }

        return $this->layanan->prediksi($model->artefak_path, $teks);
    }
}
