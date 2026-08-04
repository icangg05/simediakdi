<?php

namespace App\Services\Relevance;

use App\Models\KonteksPantauan;
use App\Models\PelatihanModelRelevansi;
use App\Models\SnapshotDatasetRelevansi;
use App\Models\User;
use App\Models\VersiKonteksRelevansi;
use App\Models\VersiModelRelevansi;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Menjembatani Laravel dan layanan pelatihan. Dokumen 10 bagian 10 dan 19.
 *
 * Laravel yang memiliki kebenaran: ia mengekspor dataset, mencatat run, dan
 * menyimpan hasilnya. Layanan Python menerima berkas, melatih, lalu
 * mengembalikan angka, dan tidak pernah menyentuh database. Aturan itu yang
 * membuat layanan Python bisa dimatikan kapan saja tanpa kehilangan apa pun
 * selain waktu.
 */
class RelevanceTrainingService
{
    public function __construct(private RelevanceDatasetExporter $exporter) {}

    /**
     * Memeriksa syarat, mengekspor dataset, lalu meminta layanan memulai.
     *
     * @param  array<string, mixed>  $konfigurasi
     */
    public function mulai(
        SnapshotDatasetRelevansi $snapshot,
        array $konfigurasi,
        User $pembuat,
        ?PelatihanModelRelevansi $induk = null,
    ): PelatihanModelRelevansi {
        $this->periksaSyarat($snapshot);

        $konteks = $this->versiKonteks();
        $cfg = array_merge(config('relevance.preset'), $konfigurasi);

        $run = PelatihanModelRelevansi::create([
            'nama' => $konfigurasi['nama'],
            'base_model' => $cfg['base_model'],
            'snapshot_dataset_relevansi_id' => $snapshot->id,
            'versi_konteks_relevansi_id' => $konteks->id,
            'versi_panduan_label' => $snapshot->versi_panduan_label,
            'parent_run_id' => $induk?->id,
            'status' => 'validasi_data',
            'configuration' => $cfg,
            'created_by' => $pembuat->id,
            'started_at' => now(),
        ]);

        try {
            $run->update(['status' => 'mengekspor_dataset']);
            $ekspor = $this->exporter->ekspor($snapshot);

            $artefak = rtrim(config('relevance.artefak_path'), '/')."/run-{$run->id}";

            $tanggapan = Http::withHeaders($this->header())
                ->timeout(config('relevance.training_timeout'))
                ->post($this->url('/relevancy/training-runs'), [
                    'run_id' => $run->id,
                    'base_model' => $cfg['base_model'],
                    'berkas' => [
                        'train' => $ekspor['berkas']['train'],
                        'validation' => $ekspor['berkas']['validation'],
                        'test' => $ekspor['berkas']['test'],
                    ],
                    'artifact_path' => $artefak,
                    'epoch' => $cfg['epoch'],
                    'batch_size' => $cfg['batch_size'],
                    'gradient_accumulation' => $cfg['gradient_accumulation'],
                    'learning_rate' => $cfg['learning_rate'],
                    'weight_decay' => $cfg['weight_decay'],
                    'warmup_ratio' => $cfg['warmup_ratio'],
                    'max_length' => $cfg['max_length'],
                    'class_weighting' => $cfg['class_weighting'],
                    'random_seed' => $cfg['random_seed'],
                    'metric_utama' => $cfg['metric_utama'],
                    'versi_input_builder' => RelevanceInputBuilder::VERSI,
                    'snapshot_manifest_hash' => $snapshot->manifest_hash,
                ]);

            if (! $tanggapan->successful()) {
                throw new RuntimeException('Layanan pelatihan menolak: '.$tanggapan->body());
            }

            $run->update([
                'status' => 'menunggu',
                'artifact_path' => $artefak,
                'runtime_info' => ['ekspor' => $ekspor['jumlah']],
            ]);
        } catch (\Throwable $e) {
            // Kegagalan tetap menyisakan barisnya, lengkap dengan konfigurasi
            // dan snapshot yang dipakai. Run yang dihapus saat gagal berarti
            // percobaan yang sama akan diulang orang berikutnya.
            $run->update([
                'status' => 'gagal',
                'error_summary' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);

            throw $e;
        }

        return $run->fresh();
    }

    /**
     * Menarik status terakhir dari layanan dan menyalinnya ke database.
     *
     * @return array<string, mixed>|null
     */
    public function tarikStatus(PelatihanModelRelevansi $run): ?array
    {
        $tanggapan = Http::withHeaders($this->header())->timeout(30)
            ->get($this->url("/relevancy/training-runs/{$run->id}"));

        if ($tanggapan->status() === 404) {
            // Layanan dimulai ulang selagi pelatihan berjalan. Statusnya hilang
            // bersama prosesnya, dan menunggunya selamanya hanya membuat job
            // polling berputar tanpa akhir.
            $run->update([
                'status' => 'gagal',
                'error_summary' => 'Layanan pelatihan kehilangan jejak run ini, kemungkinan dimulai ulang saat pelatihan berjalan.',
                'finished_at' => now(),
            ]);

            return null;
        }

        if (! $tanggapan->successful()) {
            return null;
        }

        $data = $tanggapan->json();

        $run->update(array_filter([
            'status' => $data['status'] ?? null,
            'progress' => $data['progress'] ?? null,
            'current_epoch' => (int) ($data['epoch'] ?? 0) ?: null,
            'current_step' => $data['step'] ?? null,
            'total_steps' => $data['total_step'] ?? null,
            'metrics_validation' => $data['metrics_validation'] ?: null,
            'metrics_test' => $data['metrics_test'] ?: null,
            'artifact_manifest' => $data['artifact_manifest'] ?: null,
            'error_summary' => $data['error_summary'] ?? null,
            'finished_at' => in_array($data['status'] ?? '', ['selesai', 'gagal', 'dibatalkan'], true) ? now() : null,
        ], fn ($v) => $v !== null));

        if (($data['status'] ?? null) === 'selesai') {
            $this->buatKandidat($run->fresh());
        }

        return $data;
    }

    /**
     * Pelatihan yang berhasil menghasilkan satu versi model berstatus candidate.
     *
     * Ditaruh di sini, bukan di job pemantau, supaya jalur mana pun yang lebih
     * dulu melihat status `selesai` menghasilkan hal yang sama. Halaman yang
     * dibuka admin dan job antrean sama-sama memanggil `tarikStatus`, dan kalau
     * pembuatan kandidat hanya ada di salah satunya, menutup tab pada saat yang
     * salah membuat pelatihan selesai tanpa meninggalkan model apa pun.
     *
     * Bukan production. Promosi menuntut gerbang mutu lulus, evaluasi di test
     * terkunci, dan persetujuan eksplisit, dan tidak satu pun boleh terjadi
     * otomatis karena pelatihannya kebetulan tidak melempar galat.
     */
    public function buatKandidat(PelatihanModelRelevansi $run): void
    {
        if (VersiModelRelevansi::where('pelatihan_model_relevansi_id', $run->id)->exists()) {
            return;
        }

        $manifest = $run->artifact_manifest ?? [];

        VersiModelRelevansi::create([
            'nama' => $run->nama,
            'versi' => 'simedia-relevancy-run'.$run->id,
            'base_model' => $run->base_model,
            'pelatihan_model_relevansi_id' => $run->id,
            'snapshot_dataset_relevansi_id' => $run->snapshot_dataset_relevansi_id,
            'versi_konteks_relevansi_id' => $run->versi_konteks_relevansi_id,
            'status' => 'candidate',
            'artifact_path' => $run->artifact_path,
            'artifact_checksum' => $manifest['checksum'] ?? null,
            'metrics' => $run->metrics_test,
            'runtime_info' => $manifest['runtime'] ?? null,
            'quality_gate_status' => 'blocked',
        ]);
    }

    public function batalkan(PelatihanModelRelevansi $run): bool
    {
        return Http::withHeaders($this->header())->timeout(30)
            ->post($this->url("/relevancy/training-runs/{$run->id}/cancel"))
            ->json('dibatalkan', false);
    }

    /**
     * Syarat sebelum pelatihan boleh dimulai. Dokumen 10 bagian 10.4.
     *
     * Yang diperiksa di sini hanya syarat yang benar-benar bisa dilanggar
     * sekarang. Sisanya menyusul bersama fitur yang membuatnya mungkin, dan
     * pemeriksaan yang selalu benar hanya membuat orang berhenti membacanya.
     */
    private function periksaSyarat(SnapshotDatasetRelevansi $snapshot): void
    {
        if (! $snapshot->terkunci()) {
            throw new RuntimeException('Snapshot harus dikunci lebih dulu. Draft masih bisa berubah, dan pelatihan di atasnya tidak bisa dibuktikan memakai data apa.');
        }

        if ($snapshot->total_train < 50 || $snapshot->total_validation < 20 || $snapshot->total_test < 20) {
            throw new RuntimeException('Snapshot terlalu kecil untuk dilatih. Minimal 50 train, 20 validation, 20 test.');
        }

        if (PelatihanModelRelevansi::whereNotIn('status', ['selesai', 'gagal', 'dibatalkan'])->exists()) {
            throw new RuntimeException('Masih ada pelatihan yang berjalan. Tunggu sampai selesai atau batalkan lebih dulu.');
        }
    }

    /**
     * Versi konteks yang berlaku, dibuat sekali kalau belum ada.
     *
     * Model yang dilatih di bawah satu definisi konteks tidak otomatis berlaku
     * di bawah definisi lain, jadi tiap pelatihan harus menunjuk versinya.
     */
    private function versiKonteks(): VersiKonteksRelevansi
    {
        $aktif = VersiKonteksRelevansi::where('status', 'active')->first();

        if ($aktif !== null) {
            return $aktif;
        }

        $konteks = KonteksPantauan::utama();

        if ($konteks === null) {
            throw new RuntimeException('Tidak ada konteks utama. Jalankan KonteksPantauanSeeder lebih dulu.');
        }

        return VersiKonteksRelevansi::create([
            'nama' => $konteks->nama,
            'versi' => 'v1',
            'slug' => $konteks->slug.'-v1',
            'deskripsi_manusia' => $konteks->deskripsi ?? $konteks->nama,
            'deskripsi_model' => $konteks->deskripsi_model ?: $konteks->nama,
            'aturan_inklusi' => [],
            'aturan_eksklusi' => [],
            'status' => 'active',
            'created_by' => User::where('peran', 'superadmin')->value('id'),
            'activated_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function header(): array
    {
        $rahasia = config('relevance.training_secret');

        return $rahasia ? ['X-Internal-Secret' => $rahasia] : [];
    }

    private function url(string $path): string
    {
        return rtrim(config('nlp.base_url'), '/').$path;
    }
}
