<?php

namespace App\Jobs;

use App\Models\Artikel;
use App\Services\Nlp\KlienNlp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Pgvector\Laravel\Vector;

/**
 * Vektor makna artikel, untuk deduplikasi lapis 3.
 *
 * Berjalan di antrean `nlp` bersama seluruh pekerjaan model, yang hanya punya
 * satu proses. Kalau layanan NLP mati, job menumpuk di sini dan tidak ada data
 * yang hilang, artikel sudah tersimpan lengkap sejak AmbilIsiArtikel.
 *
 * Satu vektor, bukan dua. Vektor kedua dulu melayani penilaian relevansi lewat
 * kemiripan makna; sejak revisi 1.6 relevansi dinilai classifier terlatih dan
 * kolomnya dihapus. Deteksi salinan tidak berubah sama sekali, dan sekarang
 * menjadi satu-satunya tugas model embedding.
 */
class HitungEmbedding implements ShouldQueue
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

        if ($artikel->embedding !== null) {
            PeriksaDuplikat::dispatch($artikel->id);

            return;
        }

        $vektor = $nlp->embed(['passage: '.$this->teksIsi($artikel)]);

        $artikel->update(['embedding' => new Vector($vektor[0])]);

        PeriksaDuplikat::dispatch($artikel->id);
    }

    /**
     * Judul ikut disertakan karena sering memuat inti peristiwa yang tidak
     * terulang di badan berita. Isi dipotong: model embedding punya batas token
     * sendiri, dan bagian awal berita sudah memuat inti persoalannya.
     */
    private function teksIsi(Artikel $artikel): string
    {
        return mb_substr($artikel->judul.'. '.$artikel->isi, 0, 4000);
    }
}
