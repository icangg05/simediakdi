<?php

namespace App\Console\Commands;

use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Services\Nlp\JendelaKonteks;
use App\Services\Nlp\KlienNlp;
use Illuminate\Console\Command;
use Pgvector\Laravel\Vector;
use Throwable;

/**
 * Menghitung ulang vektor konteks dan vektor artikel.
 *
 * Dipakai pada tiga keadaan, dan ketiganya wajib:
 *
 * 1. Model embedding diganti. Vektor dari model berbeda tidak sebanding, dan
 *    membandingkannya menghasilkan angka yang terlihat wajar tapi tidak
 *    berarti apa-apa.
 * 2. Deskripsi konteks diubah. Skor lama dibandingkan terhadap definisi yang
 *    sudah tidak berlaku.
 * 3. Korpus lama belum punya `embedding_relevansi` sama sekali.
 *
 * Sengaja tidak menyentuh `status_dedup` mana pun. Keputusan salinan yang
 * sudah diambil adalah catatan sejarah, dan membatalkannya berarti memutus
 * tautan pemuatan kontrak yang sudah terlanjur dibuat.
 */
class HitungUlangVektor extends Command
{
    protected $signature = 'nlp:hitung-ulang-vektor
        {--paksa : Hitung ulang juga artikel yang vektornya sudah ada}
        {--batas=0 : Berhenti setelah sekian artikel, 0 berarti semua}';

    protected $description = 'Menghitung ulang vektor konteks dan vektor relevansi artikel';

    public function handle(KlienNlp $nlp, JendelaKonteks $jendela): int
    {
        if (! $nlp->sehat()) {
            $this->error('Layanan NLP tidak menjawab. Jalankan lagi setelah layanannya hidup.');

            return self::FAILURE;
        }

        $this->vektorKonteks($nlp);

        $utama = KonteksPantauan::utama();

        if ($utama === null) {
            $this->error('Tidak ada konteks utama. Jalankan KonteksPantauanSeeder lebih dulu.');

            return self::FAILURE;
        }

        return $this->vektorArtikel($nlp, $jendela, $utama);
    }

    /**
     * Sisi kueri, dihitung sekali untuk tiap konteks aktif.
     *
     * Awalan `query:` adalah ketentuan e5: konteks berperan sebagai kueri dan
     * artikel sebagai dokumen. Menukar awalannya tidak membuat kesalahan yang
     * terlihat, hanya menurunkan mutu skornya diam-diam.
     */
    private function vektorKonteks(KlienNlp $nlp): void
    {
        $konteks = KonteksPantauan::query()->aktif()->get()
            ->filter(fn (KonteksPantauan $satu) => ! empty($satu->deskripsi_model));

        if ($konteks->isEmpty()) {
            $this->warn('Tidak ada konteks aktif yang punya deskripsi_model, vektor konteks dilewati.');

            return;
        }

        $vektor = $nlp->embed(
            $konteks->map(fn (KonteksPantauan $satu) => 'query: '.$satu->deskripsi_model)->values()->all(),
        );

        foreach ($konteks->values() as $i => $satu) {
            $satu->update(['embedding' => new Vector($vektor[$i])]);
            $this->line("Vektor konteks diperbarui: {$satu->nama}");
        }
    }

    private function vektorArtikel(KlienNlp $nlp, JendelaKonteks $jendela, KonteksPantauan $utama): int
    {
        $kueri = Artikel::withoutGlobalScopes()->whereNotNull('isi');

        if (! $this->option('paksa')) {
            $kueri->whereNull('embedding_relevansi');
        }

        $total = (int) $this->option('batas') > 0
            ? min($kueri->count(), (int) $this->option('batas'))
            : $kueri->count();

        if ($total === 0) {
            $this->info('Tidak ada artikel yang perlu dihitung.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $selesai = 0;
        $gagal = 0;

        // Dipecah per batch supaya satu kegagalan jaringan tidak membatalkan
        // ribuan artikel yang sudah berhasil dihitung sebelumnya.
        $kueri->orderBy('id')->chunkById(16, function ($artikel) use (
            $nlp, $jendela, $utama, $bar, &$selesai, &$gagal, $total
        ) {
            foreach ($artikel as $satu) {
                if ($selesai + $gagal >= $total) {
                    return false;
                }

                try {
                    $vektor = $nlp->embed([
                        'passage: '.mb_substr($satu->judul.'. '.$satu->isi, 0, 4000),
                        'passage: '.$jendela->bentuk($satu, $utama),
                    ]);

                    $satu->update([
                        'embedding' => new Vector($vektor[0]),
                        'embedding_relevansi' => new Vector($vektor[1]),
                    ]);

                    $selesai++;
                } catch (Throwable $e) {
                    // Dicatat lalu dilewati. Artikel yang gagal tetap punya
                    // `embedding_relevansi` null, jadi jalan ulang perintah ini
                    // tanpa --paksa akan mencobanya lagi tanpa mengulang yang
                    // sudah berhasil.
                    $gagal++;
                    $this->newLine();
                    $this->warn("Artikel {$satu->id} gagal: {$e->getMessage()}");
                }

                $bar->advance();
            }

            return true;
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Selesai: {$selesai} artikel. Gagal: {$gagal}.");

        if ($selesai > 0) {
            $this->warn('Ambang dedup dan ambang relevansi perlu diukur ulang: vektor dari model '
                .'berbeda tidak sebanding dengan yang lama.');
        }

        return self::SUCCESS;
    }
}
