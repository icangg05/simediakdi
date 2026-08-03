<?php

namespace App\Jobs;

use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Services\Nlp\JendelaKonteks;
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

    public function handle(KlienNlp $nlp, JendelaKonteks $jendela): void
    {
        $artikel = Artikel::withoutGlobalScopes()->find($this->artikelId);

        if ($artikel === null || $artikel->isi === null) {
            return;
        }

        if ($artikel->embedding !== null && $artikel->embedding_relevansi !== null) {
            PeriksaDuplikat::dispatch($artikel->id);

            return;
        }

        $artikel->update($this->vektor($nlp, $jendela, $artikel));

        PeriksaDuplikat::dispatch($artikel->id);
    }

    /**
     * Dua vektor sekali panggil.
     *
     * Keduanya dari model yang sama tapi dari teks yang berbeda, dan itu
     * disengaja. Deduklipasi butuh gambaran artikel seutuhnya; relevansi butuh
     * gambaran yang terfokus pada bagian yang menyinggung Pemkot. Satu vektor
     * tidak bisa melayani keduanya: dengan vektor isi penuh, dua artikel
     * berbeda yang sama-sama membahas Pemkot akan terlihat seperti salinan.
     *
     * Awalan `passage:` adalah ketentuan e5. Keduanya passage karena keduanya
     * berperan sebagai dokumen; yang berperan sebagai kueri hanya deskripsi
     * konteks, dan itu diberi awalan `query:` saat vektornya dihitung.
     *
     * @return array<string, Vector>
     */
    private function vektor(KlienNlp $nlp, JendelaKonteks $jendela, Artikel $artikel): array
    {
        $konteks = KonteksPantauan::utama();

        $teks = ['passage: '.$this->teksIsi($artikel)];

        if ($konteks !== null) {
            $teks[] = 'passage: '.$jendela->bentuk($artikel, $konteks);
        }

        $vektor = $nlp->embed($teks);

        $kolom = ['embedding' => new Vector($vektor[0])];

        if (isset($vektor[1])) {
            $kolom['embedding_relevansi'] = new Vector($vektor[1]);
        }

        return $kolom;
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
