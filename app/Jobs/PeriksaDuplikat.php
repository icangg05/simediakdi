<?php

namespace App\Jobs;

use App\Models\Artikel;
use App\Services\Dedup\PencariDuplikat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deduplikasi lapis 3: kemiripan makna.
 *
 * Rantai berhenti di sini kalau artikel ternyata salinan. Itu tujuannya,
 * kalau 40% berita adalah salinan, biaya inferensi relevansi dan sentimen
 * berkurang 40% juga.
 */
class PeriksaDuplikat implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $artikelId)
    {
        $this->onQueue('nlp');
    }

    public function handle(PencariDuplikat $dedup): void
    {
        $artikel = Artikel::withoutGlobalScopes()->find($this->artikelId);

        if ($artikel === null || $artikel->status_dedup->value === 'salinan') {
            return;
        }

        $temuan = $dedup->cariIndukSemantik($artikel);

        if ($temuan !== null) {
            [$induk, $kemiripan] = $temuan;

            $dedup->tandaiSalinan($artikel, $induk, $kemiripan);

            return;
        }

        AnalisisRelevansi::dispatch($artikel->id);
    }
}
