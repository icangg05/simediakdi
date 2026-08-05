<?php

namespace App\Jobs;

use App\Console\Commands\PrediksiDatasetRelevansi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Menjalankan prediksi atas dataset dari antrean, bukan dari terminal.
 *
 * Membungkus perintah yang sudah ada alih-alih menyalin logikanya. Prediksi
 * 4.000 sampel memakan sekitar setengah jam, jadi ia mustahil dijalankan di
 * dalam satu permintaan HTTP, dan menulis ulang alurnya di dua tempat berarti
 * keduanya lambat laun berbeda.
 */
class PrediksiSampelRelevansi implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Setengah jam untuk 4.000 sampel, dilebihkan supaya tidak putus di ujung. */
    public int $timeout = 5400;

    public function __construct(public ?string $versiModel = null)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Artisan::call(PrediksiDatasetRelevansi::class, array_filter([
            '--model' => $this->versiModel,
        ]));
    }
}
