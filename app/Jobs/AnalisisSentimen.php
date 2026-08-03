<?php

namespace App\Jobs;

use App\Models\AnalisisSentimen as BarisAnalisis;
use App\Models\Artikel;
use App\Services\Nlp\KlienNlp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * F-11 dan F-12: label sentimen terhadap konteks, beserta skor keyakinannya.
 *
 * Hanya konteks yang lolos relevansi yang dinilai. Label manual tidak pernah
 * disentuh di sini, koreksi manusia selalu mengalahkan hasil model (F-13),
 * dan analisis ulang tidak boleh menghapusnya.
 */
class AnalisisSentimen implements ShouldQueue
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

    public function handle(KlienNlp $nlp): void
    {
        $artikel = Artikel::withoutGlobalScopes()->find($this->artikelId);

        if ($artikel === null || $artikel->isi === null) {
            return;
        }

        $baris = BarisAnalisis::query()
            ->with('konteks')
            ->where('artikel_id', $artikel->id)
            ->where('relevan', true)
            ->get();

        if ($baris->isNotEmpty()) {
            $this->nilai($nlp, $artikel, $baris);
        }

        $artikel->update(['status_proses' => 'selesai']);
    }

    /** @param \Illuminate\Support\Collection<int, BarisAnalisis> $baris */
    private function nilai(KlienNlp $nlp, Artikel $artikel, $baris): void
    {
        // Sama seperti AnalisisRelevansi: `id` membawa konteks_pantauan_id
        // karena artikelnya sudah tetap satu.
        $teks = mb_substr($artikel->judul.'. '.$artikel->isi, 0, 4000);

        $hasil = $nlp->sentimen(
            $baris->map(fn (BarisAnalisis $satu) => [
                'id' => $satu->konteks_pantauan_id,
                'konteks' => $satu->konteks->nama,
                'teks' => $teks,
            ])->all(),
        );

        $ambang = (float) config('nlp.ambang.sentimen');

        foreach ($baris as $satu) {
            $sentimen = $hasil[$satu->konteks_pantauan_id] ?? null;

            if ($sentimen === null) {
                continue;
            }

            $satu->update([
                'label_model' => $sentimen->label,
                'skor_negatif' => $sentimen->skorNegatif,
                'skor_netral' => $sentimen->skorNetral,
                'skor_positif' => $sentimen->skorPositif,
                'keyakinan' => $sentimen->keyakinan,
                'perlu_review' => $sentimen->perluReview($ambang),
                'model_versi' => $sentimen->modelVersi,
                'dianalisis_at' => now(),
            ]);
        }
    }
}
