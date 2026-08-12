<?php

namespace App\Console\Commands;

use App\Models\AturanAlert;
use App\Models\RiwayatAlert;
use App\Services\Alert\PemeriksaAturan;
use App\Services\Alert\PengirimTelegram;
use App\Services\Alert\PesanAlert;
use Illuminate\Console\Command;

class PeriksaAlert extends Command
{
    protected $signature = 'alert:periksa
        {--aturan= : Hanya satu aturan, dari id-nya}
        {--kering : Nilai aturan dan tampilkan hasilnya tanpa mengirim apa pun}';

    protected $description = 'Menilai seluruh aturan alert aktif dan mengirim yang terpicu';

    public function handle(PemeriksaAturan $pemeriksa, PengirimTelegram $telegram): int
    {
        $aturan = AturanAlert::query()
            ->where('aktif', true)
            // Alert berita negatif dikirim KirimAlertBeritaNegatif tepat setelah
            // artikelnya dinilai, jadi ia tidak punya urusan dengan penilaian
            // berkala. Tanpa baris ini ia ikut terbaca tiap 15 menit, selalu
            // menghasilkan null, dan muncul di hitungan "dilewati" seolah ada
            // yang menahannya.
            ->where('jenis', '!=', 'berita_negatif')
            ->when($this->option('aturan'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($aturan->isEmpty()) {
            $this->info('Tidak ada aturan alert aktif.');

            return self::SUCCESS;
        }

        $terkirim = 0;
        $dilewati = 0;

        foreach ($aturan as $satu) {
            // F-40. Diperiksa sebelum aturannya dinilai, bukan sesudah:
            // beberapa aturan menjalankan kueri yang tidak murah, dan
            // menjalankannya tiap 15 menit untuk hasil yang pasti dibuang
            // adalah beban database yang tidak ada gunanya.
            if (! $satu->bolehDikirim()) {
                $dilewati++;

                continue;
            }

            $hasil = $pemeriksa->nilai($satu);

            if ($hasil === null) {
                continue;
            }

            // Berita contoh diambil dari payload aturannya sendiri, dan
            // kuncinya memang berbeda per jenis. Aturan yang tidak menyediakan
            // apa pun, misalnya sumber feed mati, mengirim ringkasannya saja.
            $pesan = PesanAlert::alert(
                $satu,
                $hasil['ringkasan'],
                $hasil['payload']['contoh'] ?? $hasil['payload']['artikel'] ?? [],
            );

            if ($this->option('kering')) {
                $this->line("[uji] {$satu->nama}: {$hasil['ringkasan']}");

                continue;
            }

            $kirim = $telegram->kirim($pesan);

            // Riwayat ditulis baik saat berhasil maupun gagal. Alert yang gagal
            // terkirim tanpa jejak adalah kegagalan yang tidak pernah diketahui
            // siapa pun sampai ada yang bertanya kenapa tidak ada kabar.
            RiwayatAlert::create([
                'aturan_alert_id' => $satu->id,
                'dipicu_at' => now(),
                'ringkasan' => mb_substr($hasil['ringkasan'], 0, 500),
                'payload' => $hasil['payload'],
                'status_kirim' => $kirim['terkirim'] ? 'terkirim' : 'gagal',
                'pesan_error' => $kirim['error'],
            ]);

            // Ditandai terpicu meskipun pengiriman gagal. Kalau tidak, aturan
            // yang gagal kirim akan mencoba lagi tiap 15 menit dan membanjiri
            // riwayat dengan baris gagal yang sama.
            $satu->update(['dipicu_terakhir_at' => now()]);

            $terkirim++;
            $this->line(($kirim['terkirim'] ? 'terkirim' : 'GAGAL')." - {$satu->nama}: {$hasil['ringkasan']}");
        }

        $this->info("Selesai. {$terkirim} alert diproses, {$dilewati} dilewati karena masih dalam jeda.");

        return self::SUCCESS;
    }
}
