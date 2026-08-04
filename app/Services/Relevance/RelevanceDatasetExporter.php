<?php

namespace App\Services\Relevance;

use App\Models\ItemSnapshotDatasetRelevansi;
use App\Models\KonteksPantauan;
use App\Models\SnapshotDatasetRelevansi;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Menulis snapshot terkunci menjadi berkas JSONL per split.
 *
 * Hanya snapshot terkunci yang boleh diekspor. Draft masih bisa berubah, dan
 * pelatihan yang membaca draft tidak bisa dibuktikan memakai data apa. Itu
 * bukan kehati-hatian berlebihan: seluruh gunanya manifest hash hilang kalau
 * data yang dilatihkan boleh berbeda dari data yang dibekukan.
 *
 * Label ditulis dari `label_at_snapshot`, bukan dari sampelnya. Sampel bisa
 * dikoreksi kapan saja, dan snapshot yang mengikuti koreksi itu berhenti
 * menjadi snapshot.
 */
class RelevanceDatasetExporter
{
    public function __construct(private RelevanceInputBuilder $builder) {}

    /**
     * @return array{direktori: string, berkas: array<string, string>, jumlah: array<string, int>}
     */
    public function ekspor(SnapshotDatasetRelevansi $snapshot): array
    {
        if (! $snapshot->terkunci()) {
            throw new RuntimeException('Hanya snapshot terkunci yang bisa diekspor. Kunci dulu snapshot ini.');
        }

        $konteks = KonteksPantauan::utama();

        if ($konteks === null) {
            throw new RuntimeException('Tidak ada konteks utama. Jalankan KonteksPantauanSeeder lebih dulu.');
        }

        $direktori = $this->direktori($snapshot);
        File::ensureDirectoryExists($direktori, 0755, recursive: true);

        $berkas = [];
        $jumlah = [];

        foreach (['train', 'validation', 'test'] as $split) {
            $path = "{$direktori}/{$split}.jsonl";
            $jumlah[$split] = $this->tulisSplit($snapshot, $split, $konteks, $path);
            $berkas[$split] = $path;
        }

        $berkas['manifest'] = "{$direktori}/manifest.json";
        File::put($berkas['manifest'], json_encode([
            'snapshot_id' => $snapshot->id,
            'nama' => $snapshot->nama,
            'versi' => $snapshot->versi,
            'manifest_hash' => $snapshot->manifest_hash,
            'random_seed' => $snapshot->random_seed,
            'strategi_sampling' => $snapshot->strategi_sampling,
            'versi_panduan_label' => $snapshot->versi_panduan_label,
            'versi_input_builder' => RelevanceInputBuilder::VERSI,
            'konteks' => $this->builder->konteks($konteks),
            'jumlah' => $jumlah,
            'diekspor_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ['direktori' => $direktori, 'berkas' => $berkas, 'jumlah' => $jumlah];
    }

    /**
     * Satu baris JSON per sampel, ditulis mengalir.
     *
     * Bukan satu array besar yang di-encode sekali. Dataset akan tumbuh ke
     * puluhan ribu baris, dan membangun seluruhnya di memori lebih dulu adalah
     * cara paling mudah proses ekspor mati justru saat datasetnya sudah
     * berharga.
     */
    private function tulisSplit(
        SnapshotDatasetRelevansi $snapshot,
        string $split,
        KonteksPantauan $konteks,
        string $path,
    ): int {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new RuntimeException("Tidak bisa menulis {$path}.");
        }

        $teksKonteks = $this->builder->konteks($konteks);
        $jumlah = 0;

        ItemSnapshotDatasetRelevansi::with('sampel')
            ->where('snapshot_dataset_relevansi_id', $snapshot->id)
            ->where('split', $split)
            ->orderBy('sampel_relevansi_id')
            ->chunk(200, function ($daftar) use ($handle, $konteks, $teksKonteks, &$jumlah) {
                foreach ($daftar as $item) {
                    if ($item->sampel === null) {
                        continue;
                    }

                    $teks = $this->builder->dariSampel($item->sampel, $konteks);

                    fwrite($handle, json_encode([
                        'id' => $item->sampel_relevansi_id,
                        'konteks' => $teksKonteks,
                        'teks' => $teks,
                        // 1 = relevan, mengikuti id2label base model
                        // (0 NOT_RELEVANT, 1 RELEVANT). Kalau dibalik, model
                        // belajar kebalikannya dan tetap terlihat wajar.
                        'label' => $item->label_at_snapshot->value === 'relevan' ? 1 : 0,
                        'input_hash' => $this->builder->inputHash($teksKonteks, $teks),
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

                    $jumlah++;
                }
            });

        fclose($handle);

        return $jumlah;
    }

    public function direktori(SnapshotDatasetRelevansi $snapshot): string
    {
        return rtrim(config('relevance.dataset_path'), '/')."/snapshot-{$snapshot->id}";
    }
}
