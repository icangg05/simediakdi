<?php

namespace App\Jobs;

use App\Models\AnalisisSentimen as BarisAnalisis;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Services\Nlp\KlienNlp;
use App\Services\Nlp\PenyaringKataKunci;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * F-10: menilai relevansi terhadap setiap konteks sebelum menilai sentimen.
 *
 * Urutan ini bukan selera. Model sentimen tetap mengeluarkan label untuk
 * artikel yang tidak relevan, label itu masuk agregasi, lalu grafik dashboard
 * terisi angka yang tidak ada hubungannya dengan Pemkot Kendari. Saringan
 * relevansi membuangnya lebih dulu.
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

    public function handle(KlienNlp $nlp, PenyaringKataKunci $penyaring): void
    {
        $artikel = Artikel::withoutGlobalScopes()->find($this->artikelId);

        if ($artikel === null || $artikel->isi === null) {
            return;
        }

        $konteks = KonteksPantauan::query()->aktif()->get();

        // Saringan kata kunci dulu: konteks yang jelas tidak nyambung tidak
        // perlu dibayar dengan satu panggilan model.
        $lolos = $penyaring->saring($artikel->judul.' '.$artikel->isi, $konteks);

        $hasil = $lolos === [] ? [] : $nlp->relevansi($this->pasangan($artikel, $lolos));

        foreach ($konteks as $satu) {
            $relevansi = $hasil[$satu->id] ?? null;

            // Model saja terlalu royal — ia menganggap penyebutan sekali lewat
            // sebagai pembahasan. Pengetat ini menaikkan presisi 54% ke 80%
            // pada gold set; alasannya lengkap di PenyaringKataKunci::menonjol().
            $relevan = ($relevansi?->relevan ?? false)
                && $penyaring->menonjol($artikel->judul, $artikel->isi, $satu);

            BarisAnalisis::updateOrCreate(
                ['artikel_id' => $artikel->id, 'konteks_pantauan_id' => $satu->id],
                [
                    // Konteks yang tidak lolos saringan dicatat sebagai tidak
                    // relevan, bukan dihilangkan. Barisnya ada supaya terlihat
                    // konteks itu memang sudah dinilai.
                    'relevan' => $relevan,
                    'keyakinan_relevansi' => $relevansi?->keyakinan,
                ],
            );
        }

        $artikel->update(['status_proses' => 'dianalisis']);

        AnalisisSentimen::dispatch($artikel->id);
    }

    /**
     * Seluruh konteks dikirim sekali jalan supaya model memprosesnya sebagai
     * satu batch, bukan tiga forward pass terpisah.
     *
     * Konsekuensinya `id` di sini membawa `konteks_pantauan_id`, bukan
     * `artikel_id` seperti contoh di dokumen 02 bagian 6. Artikelnya memang
     * sudah tetap satu, jadi yang perlu dibedakan justru konteksnya — dan
     * tujuan aslinya, memetakan hasil tanpa bergantung urutan array, tetap
     * terpenuhi.
     *
     * @param  list<KonteksPantauan>  $konteks
     * @return list<array{id: int, konteks: string, teks: string}>
     */
    private function pasangan(Artikel $artikel, array $konteks): array
    {
        // Model dipotong di 512 token; kirim bagian awal yang memuat inti berita.
        $teks = mb_substr($artikel->judul.'. '.$artikel->isi, 0, 4000);

        return array_map(
            fn (KonteksPantauan $satu) => [
                'id' => $satu->id,
                'konteks' => $satu->nama,
                'teks' => $teks,
            ],
            $konteks,
        );
    }
}
