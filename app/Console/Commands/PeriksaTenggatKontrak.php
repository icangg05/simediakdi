<?php

namespace App\Console\Commands;

use App\Models\Kontrak;
use App\Services\Alert\PengirimTelegram;
use App\Services\Kontrak\PencocokPemuatan;
use Illuminate\Console\Command;

/**
 * F-26: memperingatkan kontrak yang tenggatnya dekat sementara realisasinya
 * masih jauh dari target.
 *
 * Terpisah dari `alert:periksa` karena jadwalnya berbeda dan sifatnya berbeda.
 * Aturan alert dinilai tiap 15 menit dan mengabarkan hal yang baru terjadi.
 * Ini dijalankan sekali sehari pagi hari, dan mengabarkan hal yang sudah lama
 * bergerak pelan ke arah yang salah.
 */
class PeriksaTenggatKontrak extends Command
{
    protected $signature = 'kontrak:periksa-tenggat
        {--hari=14 : Ambang sisa hari yang dianggap mendekati tenggat}
        {--kering : Tampilkan hasilnya tanpa mengirim apa pun}';

    protected $description = 'Memperingatkan kontrak yang mendekati tenggat dengan realisasi tertinggal';

    public function handle(PencocokPemuatan $pencocok, PengirimTelegram $telegram): int
    {
        $ambangHari = (int) $this->option('hari');

        $perlu = Kontrak::withoutGlobalScopes()
            ->where('status', 'aktif')
            ->with('media:id,nama')
            ->get()
            ->map(fn (Kontrak $k) => ['kontrak' => $k, 'progres' => $pencocok->progres($k)])
            ->filter(function (array $baris) use ($ambangHari) {
                $p = $baris['progres'];

                // Dua syarat sekaligus. Tenggat dekat tapi target sudah
                // tercapai bukan masalah, dan tertinggal jauh tapi masih dua
                // bulan lagi masih bisa dikejar.
                return $p['target'] !== null
                    && $p['sisa_hari'] <= $ambangHari
                    && $p['terverifikasi'] < $p['target'];
            })
            ->values();

        if ($perlu->isEmpty()) {
            $this->info('Tidak ada kontrak yang mendekati tenggat dengan realisasi kurang.');

            return self::SUCCESS;
        }

        $baris = $perlu->map(fn (array $b) => sprintf(
            '%s: %d dari %d pemuatan, sisa %d hari',
            $b['kontrak']->media?->nama ?? $b['kontrak']->judul,
            $b['progres']['terverifikasi'],
            $b['progres']['target'],
            $b['progres']['sisa_hari'],
        ));

        $baris->each(fn (string $t) => $this->line($t));

        if ($this->option('kering')) {
            return self::SUCCESS;
        }

        $hasil = $telegram->kirim(
            "<b>Kontrak mendekati tenggat</b>\n".$baris->implode("\n")
        );

        if (! $hasil['terkirim']) {
            $this->warn("Telegram tidak menerima pesan: {$hasil['error']}");
        }

        return self::SUCCESS;
    }
}
