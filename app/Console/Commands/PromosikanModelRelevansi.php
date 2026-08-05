<?php

namespace App\Console\Commands;

use App\Models\GerbangMutuRelevansi;
use App\Models\User;
use App\Models\VersiModelRelevansi;
use App\Models\VersiThresholdRelevansi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mempromosikan satu model kandidat menjadi model produksi. Dokumen 10 bagian 12.
 *
 * Promosi tidak pernah otomatis, dan itu keputusan yang sudah dibuat sejak awal.
 * Model yang lolos empat angka di atas kertas tetap keputusan manusia untuk
 * dipakai, karena yang menanggung akibat artikel salah saring bukan skrip
 * melainkan orang yang membaca dashboard.
 *
 * Perintah ini mengerjakan tiga hal yang harus terjadi bersama, di dalam satu
 * transaksi:
 *
 * 1. Membuat versi ambang, kalau belum ditunjuk. Model produksi selalu menunjuk
 *    pasangan versi model dan versi ambang sekaligus (dokumen 05 bagian 7.3).
 * 2. Mencatat penilaian gerbang mutu beserta standar dan hasilnya, supaya alasan
 *    lolosnya bisa dibaca ulang tanpa menjalankan apa pun.
 * 3. Menurunkan model produksi lama ke `archived` lalu menaikkan yang baru.
 *
 * Ketiganya satu transaksi karena keadaan setengah jadi di sini berbahaya:
 * model berstatus produksi tanpa ambang akan membuat setiap job relevansi
 * melempar, dan artikel menumpuk di antrean tanpa ada yang tahu sebabnya.
 */
class PromosikanModelRelevansi extends Command
{
    protected $signature = 'relevance:promosikan
        {versi : Versi model yang dipromosikan}
        {--ambang= : relevant_threshold, bawaannya 0.5}
        {--pita=0.15 : Setengah lebar pita review di sekitar ambang}
        {--alasan= : Alasan promosi, wajib}
        {--paksa : Promosikan meski ada syarat gerbang yang tidak terpenuhi}';

    protected $description = 'Mempromosikan model relevansi kandidat menjadi model produksi';

    /** Dokumen 05 bagian 7.1 dan dokumen 10 bagian 11. */
    private const STANDAR = [
        'precision_relevan' => 0.85,
        'recall_relevan' => 0.85,
        'f1_relevan' => 0.85,
        'macro_f1' => 0.85,
    ];

    public function handle(): int
    {
        $model = VersiModelRelevansi::where('versi', $this->argument('versi'))->first();

        if ($model === null) {
            $this->error("Model versi {$this->argument('versi')} tidak ditemukan.");

            return self::FAILURE;
        }

        $alasan = (string) $this->option('alasan');

        if (trim($alasan) === '') {
            $this->error('Alasan promosi wajib diisi. Ia yang menjawab pertanyaan "mengapa model ini" enam bulan lagi.');

            return self::FAILURE;
        }

        [$hasil, $gagal] = $this->nilai($model);

        $this->tampilkanPenilaian($hasil, $gagal);

        if ($gagal !== [] && ! $this->option('paksa')) {
            $this->error('Gerbang mutu tidak terpenuhi. Pakai --paksa kalau promosi tetap dikehendaki, dan alasannya akan tercatat.');

            return self::FAILURE;
        }

        $pengguna = User::where('peran', 'superadmin')->orderBy('id')->first();

        if ($pengguna === null) {
            $this->error('Tidak ada superadmin yang bisa dicatat sebagai pemberi persetujuan.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($model, $hasil, $gagal, $alasan, $pengguna) {
            $ambang = $this->ambang($model, $pengguna, $alasan);

            GerbangMutuRelevansi::create([
                'versi_model_relevansi_id' => $model->id,
                // Dipaksa berarti belum benar-benar lolos, dan statusnya harus
                // mengatakan itu. `needs_review` tetap mengizinkan sentimen
                // berjalan tetapi meninggalkan tanda bahwa angkanya berutang.
                'status' => $gagal === [] ? 'passed' : 'needs_review',
                'standar' => self::STANDAR,
                'hasil' => $hasil,
                'failed_checks' => $gagal === [] ? null : $gagal,
                'approved_by' => $pengguna->id,
                'approved_at' => now(),
            ]);

            // Hanya satu boleh berstatus production, dijaga unique partial index
            // di database. Menurunkan yang lama harus terjadi lebih dulu, kalau
            // tidak insert berikutnya ditolak.
            VersiModelRelevansi::produksi()->update([
                'status' => 'archived',
                'archived_at' => now(),
            ]);

            $model->update([
                'status' => 'production',
                'versi_threshold_relevansi_id' => $ambang->id,
                'quality_gate_status' => $gagal === [] ? 'passed' : 'needs_review',
                'quality_gate_report' => ['standar' => self::STANDAR, 'hasil' => $hasil, 'gagal' => $gagal],
                'promoted_by' => $pengguna->id,
                'promotion_reason' => $alasan,
                'activated_at' => now(),
            ]);
        });

        $this->newLine();
        $this->info("Model {$model->versi} sekarang berstatus production.");
        $this->line('Artikel yang tertahan bisa diproses ulang dengan: php artisan relevance:proses-tertahan');

        return self::SUCCESS;
    }

    /**
     * Menilai metrik test model terhadap standar gerbang.
     *
     * Dibaca dari metrik test yang tersimpan, bukan dihitung ulang. Metrik itu
     * dihasilkan saat pelatihan atas split test snapshot yang terkunci, dan
     * menghitungnya ulang di sini justru membuka peluang mengukur dengan data
     * yang sudah berubah.
     *
     * @return array{0: array<string, float|null>, 1: array<string, string>}
     */
    private function nilai(VersiModelRelevansi $model): array
    {
        $metrik = $model->metrics['test'] ?? $model->metrics ?? [];

        $hasil = [
            'precision_relevan' => $this->angka($metrik, 'precision_relevan'),
            'recall_relevan' => $this->angka($metrik, 'recall_relevan'),
            'f1_relevan' => $this->angka($metrik, 'f1_relevan'),
            'macro_f1' => $this->angka($metrik, 'macro_f1'),
        ];

        $gagal = [];

        foreach (self::STANDAR as $nama => $minimal) {
            $nilai = $hasil[$nama];

            if ($nilai === null) {
                $gagal[$nama] = 'Tidak ada angkanya di metrik test.';

                continue;
            }

            if ($nilai < $minimal) {
                $gagal[$nama] = "{$nilai} di bawah minimal {$minimal}.";
            }
        }

        return [$hasil, $gagal];
    }

    /** @param array<string, mixed> $metrik */
    private function angka(array $metrik, string $kunci): ?float
    {
        return isset($metrik[$kunci]) ? round((float) $metrik[$kunci], 4) : null;
    }

    /**
     * Versi ambang yang dipakai model ini.
     *
     * Dibuat baru tiap promosi, tidak dipakai ulang. Sebaran skor tiap model
     * berbeda, jadi ambang 0,5 pada model lama dan model baru bukan hal yang
     * sama, dan menyambungkan keduanya ke satu baris membuat riwayatnya
     * berbohong.
     */
    private function ambang(VersiModelRelevansi $model, User $pengguna, string $alasan): VersiThresholdRelevansi
    {
        $nilai = (float) ($this->option('ambang') ?? 0.5);
        $pita = (float) $this->option('pita');

        return VersiThresholdRelevansi::create([
            'nama' => "ambang-{$model->versi}",
            'relevant_threshold' => $nilai,
            'review_lower_bound' => max(0.0, $nilai - $pita),
            'review_upper_bound' => min(1.0, $nilai + $pita),
            'reason' => $alasan,
            'status' => 'active',
            'created_by' => $pengguna->id,
            'activated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, float|null>  $hasil
     * @param  array<string, string>  $gagal
     */
    private function tampilkanPenilaian(array $hasil, array $gagal): void
    {
        $this->table(
            ['Syarat', 'Minimal', 'Hasil', 'Status'],
            collect(self::STANDAR)->map(fn ($minimal, $nama) => [
                $nama,
                $minimal,
                $hasil[$nama] ?? '-',
                isset($gagal[$nama]) ? 'GAGAL' : 'lolos',
            ])->values()->all(),
        );
    }
}
