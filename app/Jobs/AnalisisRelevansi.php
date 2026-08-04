<?php

namespace App\Jobs;

use App\Models\Artikel;
use App\Services\Relevance\RelevanceQualityGateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * F-10: menilai relevansi artikel sebelum menilai sentimen.
 *
 * Urutan ini bukan selera. Model sentimen tetap mengeluarkan label untuk
 * artikel yang tidak relevan, label itu masuk agregasi, lalu grafik dashboard
 * terisi angka yang tidak ada hubungannya dengan Pemkot Kendari. Saringan
 * relevansi membuangnya lebih dulu.
 *
 * Sejak revisi 1.6 penilainya kembali berupa classifier, kali ini hasil
 * fine-tuning dengan dataset lokal. Selama belum ada model produksi yang lolos
 * gerbang mutu, job ini tidak menebak-nebak: artikel ditandai
 * `model_belum_lulus_gate` dan menunggu. Menahan artikel adalah keputusan yang
 * bisa dibatalkan, sedangkan angka salah yang sudah masuk dashboard dan dibaca
 * pimpinan tidak bisa ditarik kembali.
 *
 * Dokumen 05 bagian 2, dokumen 10 bagian 1.1 dan 21.
 */
class AnalisisRelevansi implements ShouldQueue
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

    public function handle(RelevanceQualityGateService $gerbang): void
    {
        $artikel = Artikel::withoutGlobalScopes()->find($this->artikelId);

        if ($artikel === null || $artikel->isi === null) {
            return;
        }

        // Artikel tetap dikumpulkan dan tetap masuk dataset untuk dilabeli.
        // Yang berhenti hanya penilaian otomatis dan segala yang mengikutinya.
        if (! $gerbang->lolos()) {
            $artikel->update(['status_proses' => 'model_belum_lulus_gate']);

            return;
        }

        // Inferensi classifier menyusul pada fase 3 sprint 8, bersama model
        // pertama yang benar-benar ada untuk dipanggil.
        //
        // Sengaja melempar, bukan diam. Sampai fase itu selesai, satu-satunya
        // cara baris ini tercapai adalah ada yang menandai sebuah versi model
        // sebagai produksi lewat database. Job yang diam-diam tidak mengerjakan
        // apa pun akan terlihat persis seperti job yang berhasil, dan artikel
        // menumpuk tanpa ada yang tahu sebabnya.
        throw new \LogicException(
            'Gerbang mutu lolos tetapi inferensi relevansi belum tersedia. Lihat fase 3 sprint 8, dokumen 07.'
        );
    }
}
