<?php

namespace App\Console\Commands;

use App\Enums\StatusDedup;
use App\Jobs\ImporArtikelKeDatasetRelevansi;
use App\Models\Artikel;
use App\Models\SampelRelevansi;
use App\Services\Relevance\SkorPrioritasPelabelan;
use Illuminate\Console\Command;

/**
 * Memasukkan artikel yang sudah terkumpul ke dataset relevansi.
 *
 * Artikel baru masuk sendiri lewat rantai job. Perintah ini untuk korpus yang
 * sudah ada sebelum laboratorium dibangun, dan untuk menghitung ulang skor
 * prioritas setelah rumusnya berubah.
 *
 * Dijalankan langsung, bukan lewat antrean. Empat ribu job untuk pekerjaan
 * yang tidak memanggil apa pun di luar database hanya membuat antrean penuh
 * dan kemajuannya tidak terlihat. Rantai job tetap memakai versi job-nya
 * karena di sana artikelnya datang satu per satu.
 */
class ImporDatasetRelevansi extends Command
{
    protected $signature = 'relevance:import-crawled
        {--hitung-ulang-prioritas : Perbarui skor prioritas sampel yang sudah ada}
        {--batas=0 : Berhenti setelah sekian artikel, 0 berarti semua}';

    protected $description = 'Memasukkan artikel hasil crawler ke dataset relevansi';

    public function handle(SkorPrioritasPelabelan $prioritas): int
    {
        $kueri = Artikel::withoutGlobalScopes()
            ->with('media:id,nama')
            ->whereNotNull('isi')
            ->where('status_dedup', StatusDedup::Asli);

        if (! $this->option('hitung-ulang-prioritas')) {
            // Salinan sengaja tidak diimpor: ia tidak menambah informasi bagi
            // model, dan kalau jatuh di split berbeda dari induknya, angka
            // evaluasi akan bohong ke atas.
            $kueri->whereNotExists(fn ($sub) => $sub->selectRaw('1')
                ->from('sampel_relevansi')
                ->whereColumn('sampel_relevansi.artikel_id', 'artikel.id'));
        }

        $batas = (int) $this->option('batas');
        $total = $batas > 0 ? min($kueri->count(), $batas) : $kueri->count();

        if ($total === 0) {
            $this->info('Tidak ada artikel yang perlu diimpor.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $baru = 0;
        $diperbarui = 0;

        $kueri->orderBy('id')->chunkById(200, function ($artikel) use (
            $prioritas, $bar, &$baru, &$diperbarui, $total
        ) {
            foreach ($artikel as $satu) {
                if ($baru + $diperbarui >= $total) {
                    return false;
                }

                $sudahAda = SampelRelevansi::where('artikel_id', $satu->id)->exists();

                (new ImporArtikelKeDatasetRelevansi($satu->id))->handle($prioritas);

                $sudahAda ? $diperbarui++ : $baru++;
                $bar->advance();
            }

            return true;
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Sampel baru: {$baru}. Prioritas diperbarui: {$diperbarui}.");

        $this->ringkasKesiapan();

        return self::SUCCESS;
    }

    /**
     * Angka yang menentukan kapan pelatihan pertama masuk akal.
     *
     * Ditampilkan tiap kali impor selesai karena inilah satu-satunya pertanyaan
     * yang benar-benar penting selama fase 1 dan 2, dan gampang dilupakan
     * ketika yang terlihat hanya jumlah baris yang bertambah.
     */
    private function ringkasKesiapan(): void
    {
        $berlabel = SampelRelevansi::layakLatih()->count();
        $relevan = SampelRelevansi::layakLatih()->where('label_manual', 'relevan')->count();
        $tidak = $berlabel - $relevan;

        $this->newLine();
        $this->line("Artikel berlabel: {$berlabel} ({$relevan} relevan, {$tidak} tidak relevan)");
        $this->line('Kandidat menunggu label: '.SampelRelevansi::belumDilabeli()->count());

        $target = match (true) {
            $berlabel >= 3000 => null,
            $berlabel >= 1500 => 'Kandidat produksi butuh 3.000 artikel unik, 1.200 per kelas.',
            $berlabel >= 500 => 'Fine-tuning awal butuh 1.500 artikel unik, 600 per kelas.',
            default => 'Tingkat eksperimen butuh 500 artikel unik, 200 per kelas.',
        };

        if ($target !== null) {
            $this->warn($target.' Dokumen 10 bagian 9.3.');
        }
    }
}
