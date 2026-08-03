<?php

namespace App\Console\Commands;

use App\Models\Artikel;
use App\Services\Nlp\PencocokEntitas;
use Illuminate\Console\Command;

class HitungEntitas extends Command
{
    protected $signature = 'hitung:entitas
        {--hari=7 : Rentang artikel yang dicocokkan, dihitung dari diambil_at}
        {--semua : Cocokkan seluruh artikel, abaikan --hari}';

    protected $description = 'Mencocokkan kamus entitas ke artikel dan mengisi artikel_entitas';

    public function handle(PencocokEntitas $pencocok): int
    {
        $kamus = $pencocok->kamus();

        if ($kamus->isEmpty()) {
            $this->warn('Kamus entitas kosong. Tambahkan entitas di /admin/entitas lebih dulu.');

            return self::SUCCESS;
        }

        $kueri = Artikel::withoutGlobalScopes()
            ->asli()
            ->whereNotNull('isi')
            ->when(! $this->option('semua'), fn ($q) => $q
                ->where('diambil_at', '>=', now()->subDays((int) $this->option('hari'))));

        $total = $kueri->count();
        $bar = $this->output->createProgressBar($total);
        $ketemu = 0;

        // chunkById: jumlah artikelnya tidak dibatasi apa pun selain rentang
        // tanggal, dan --semua sengaja tidak dibatasi sama sekali.
        $kueri->chunkById(200, function ($kumpulan) use ($pencocok, $kamus, $bar, &$ketemu) {
            foreach ($kumpulan as $artikel) {
                $ketemu += $pencocok->cocokkan($artikel, $kamus) > 0 ? 1 : 0;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("{$ketemu} dari {$total} artikel memuat entitas dari kamus ({$kamus->count()} entitas).");

        return self::SUCCESS;
    }
}
