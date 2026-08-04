<?php

namespace App\Jobs;

use App\Models\PelatihanModelRelevansi;
use App\Services\Relevance\RelevanceTrainingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Menyalin kemajuan pelatihan dari layanan Python ke database.
 *
 * Pembuatan model kandidat ada di `RelevanceTrainingService::tarikStatus`,
 * bukan di sini. Halaman Pelatihan juga menarik status saat dibuka, dan kalau
 * kandidat hanya dibuat oleh job ini, siapa pun yang menjalankan sistem tanpa
 * worker antrean akan menemukan pelatihan yang selesai tanpa meninggalkan
 * model apa pun.
 *
 * Polling, bukan callback. Layanan Python tidak boleh menyentuh database, dan
 * callback HTTP ke Laravel berarti menambah satu endpoint yang harus dijaga
 * demi menghemat satu kueri tiap sepuluh detik.
 *
 * Menjadwalkan dirinya sendiri sampai pelatihan berhenti. Antreannya `default`,
 * bukan `nlp`: antrean `nlp` cuma punya satu proses, dan job yang menunggu di
 * sana akan menghalangi pekerjaan lain selama belasan menit.
 */
class PantauPelatihanRelevansi implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Sekitar dua jam pada jeda 10 detik. Pelatihan yang lebih lama dari itu
     * hampir pasti tergantung, dan job yang berputar selamanya menyembunyikannya. */
    private const MAKS_PUTARAN = 720;

    public function __construct(
        public int $pelatihanId,
        public int $putaran = 0,
    ) {
        $this->onQueue('default');
    }

    public function handle(RelevanceTrainingService $service): void
    {
        $run = PelatihanModelRelevansi::find($this->pelatihanId);

        if ($run === null || $run->selesai()) {
            return;
        }

        if ($this->putaran >= self::MAKS_PUTARAN) {
            $run->update([
                'status' => 'gagal',
                'error_summary' => 'Pelatihan melewati batas waktu pemantauan, kemungkinan tergantung.',
                'finished_at' => now(),
            ]);

            return;
        }

        $service->tarikStatus($run);

        $run->refresh();

        if ($run->selesai()) {
            return;
        }

        self::dispatch($this->pelatihanId, $this->putaran + 1)->delay(now()->addSeconds(10));
    }
}
