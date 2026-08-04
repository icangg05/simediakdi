<?php

namespace App\Console\Commands;

use App\Models\Artikel;
use App\Services\Nlp\KlienNlp;
use Illuminate\Console\Command;
use Pgvector\Laravel\Vector;
use Throwable;

/**
 * Menghitung ulang vektor artikel untuk deteksi salinan.
 *
 * Dipakai pada dua keadaan, dan keduanya wajib:
 *
 * 1. Model embedding diganti. Vektor dari model berbeda tidak sebanding, dan
 *    membandingkannya menghasilkan angka yang terlihat wajar tapi tidak
 *    berarti apa-apa.
 * 2. Korpus lama belum punya `embedding` sama sekali.
 *
 * Sejak revisi 1.6 perintah ini tidak lagi mengurus vektor konteks maupun
 * vektor relevansi. Relevansi dinilai classifier terlatih, dan kedua kolom
 * vektornya sudah dihapus. Yang tersisa satu vektor untuk satu tugas.
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

    protected $description = 'Menghitung ulang vektor artikel untuk deteksi salinan';

    public function handle(KlienNlp $nlp): int
    {
        if (! $nlp->sehat()) {
            $this->error('Layanan NLP tidak menjawab. Jalankan lagi setelah layanannya hidup.');

            return self::FAILURE;
        }

        $kueri = Artikel::withoutGlobalScopes()->whereNotNull('isi');

        if (! $this->option('paksa')) {
            $kueri->whereNull('embedding');
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
            $nlp, $bar, &$selesai, &$gagal, $total
        ) {
            foreach ($artikel as $satu) {
                if ($selesai + $gagal >= $total) {
                    return false;
                }

                try {
                    $vektor = $nlp->embed([
                        'passage: '.mb_substr($satu->judul.'. '.$satu->isi, 0, 4000),
                    ]);

                    $satu->update(['embedding' => new Vector($vektor[0])]);

                    $selesai++;
                } catch (Throwable $e) {
                    // Dicatat lalu dilewati. Artikel yang gagal tetap punya
                    // `embedding` null, jadi jalan ulang perintah ini tanpa
                    // --paksa akan mencobanya lagi tanpa mengulang yang sudah
                    // berhasil.
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

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }
}
