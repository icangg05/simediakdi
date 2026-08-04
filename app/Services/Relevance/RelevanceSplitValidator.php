<?php

namespace App\Services\Relevance;

use App\Models\SnapshotDatasetRelevansi;
use Illuminate\Support\Facades\DB;

/**
 * Mencari kebocoran antar split sebelum snapshot boleh dikunci.
 *
 * Kebocoran adalah kesalahan yang paling mahal di seluruh laboratorium ini,
 * dan satu-satunya yang membuat model terlihat **lebih baik** daripada
 * sebenarnya. Kesalahan lain menurunkan angka dan langsung terlihat; kebocoran
 * menaikkannya, jadi tidak ada yang curiga sampai model itu dipakai di
 * produksi dan ternyata jauh lebih buruk daripada laporannya.
 *
 * Dokumen 10 bagian 9.5.
 */
class RelevanceSplitValidator
{
    /**
     * @return list<array{jenis: string, keterangan: string, jumlah: int, contoh: list<string>}>
     */
    public function periksa(SnapshotDatasetRelevansi $snapshot): array
    {
        return array_values(array_filter([
            $this->grupDuplikatLintasSplit($snapshot),
            $this->isiSamaLintasSplit($snapshot),
            $this->urlSamaLintasSplit($snapshot),
            $this->grupBerlabelCampur($snapshot),
            $this->splitKosong($snapshot),
        ]));
    }

    /**
     * Anggota satu grup duplikat tersebar di lebih dari satu split.
     *
     * Ini bentuk kebocoran yang paling sering terjadi dan paling langsung
     * akibatnya: model diuji dengan artikel yang salinannya sudah ia pelajari.
     */
    private function grupDuplikatLintasSplit(SnapshotDatasetRelevansi $snapshot): ?array
    {
        $temuan = DB::table('item_snapshot_dataset_relevansi')
            ->select('duplicate_group_id')
            ->where('snapshot_dataset_relevansi_id', $snapshot->id)
            ->whereNotNull('duplicate_group_id')
            ->groupBy('duplicate_group_id')
            ->havingRaw('count(DISTINCT split) > 1')
            ->pluck('duplicate_group_id');

        return $this->laporan(
            'grup_duplikat_lintas_split',
            'Grup duplikat tersebar di lebih dari satu split. Salinan berita yang sama muncul di data latih dan data uji sekaligus.',
            $temuan->map(fn ($g) => "grup {$g}")->all(),
        );
    }

    /**
     * Isi identik muncul di lebih dari satu split meski grup duplikatnya beda.
     *
     * Menangkap salinan yang lolos deduplikasi, misalnya siaran pers yang
     * dimuat dua media dengan judul berbeda tetapi badan berita sama persis.
     */
    private function isiSamaLintasSplit(SnapshotDatasetRelevansi $snapshot): ?array
    {
        $temuan = DB::table('item_snapshot_dataset_relevansi')
            ->select('content_hash')
            ->where('snapshot_dataset_relevansi_id', $snapshot->id)
            ->groupBy('content_hash')
            ->havingRaw('count(DISTINCT split) > 1')
            ->pluck('content_hash');

        return $this->laporan(
            'isi_sama_lintas_split',
            'Isi artikel identik muncul di lebih dari satu split. Kemungkinan salinan yang lolos deduplikasi.',
            $temuan->map(fn ($h) => substr($h, 0, 12))->all(),
        );
    }

    private function urlSamaLintasSplit(SnapshotDatasetRelevansi $snapshot): ?array
    {
        $temuan = DB::table('item_snapshot_dataset_relevansi as i')
            ->join('sampel_relevansi as s', 's.id', '=', 'i.sampel_relevansi_id')
            ->select('s.url')
            ->where('i.snapshot_dataset_relevansi_id', $snapshot->id)
            ->whereNotNull('s.url')
            ->groupBy('s.url')
            ->havingRaw('count(DISTINCT i.split) > 1')
            ->pluck('url');

        return $this->laporan(
            'url_sama_lintas_split',
            'URL yang sama muncul di lebih dari satu split.',
            $temuan->all(),
        );
    }

    /**
     * Satu grup duplikat punya label berbeda antar anggotanya.
     *
     * Bukan kebocoran, melainkan tanda pelabelan tidak konsisten: dua salinan
     * berita yang sama tidak mungkin satu relevan dan satu tidak. Dilaporkan
     * bersama kebocoran karena akibatnya sama, angka evaluasi yang tidak bisa
     * dipercaya, dan karena inilah kesempatan termurah menemukannya.
     */
    private function grupBerlabelCampur(SnapshotDatasetRelevansi $snapshot): ?array
    {
        $temuan = DB::table('item_snapshot_dataset_relevansi')
            ->select('duplicate_group_id')
            ->where('snapshot_dataset_relevansi_id', $snapshot->id)
            ->whereNotNull('duplicate_group_id')
            ->groupBy('duplicate_group_id')
            ->havingRaw('count(DISTINCT label_at_snapshot) > 1')
            ->pluck('duplicate_group_id');

        return $this->laporan(
            'grup_berlabel_campur',
            'Anggota satu grup duplikat punya label berbeda. Salinan berita yang sama tidak mungkin satu relevan dan satu tidak.',
            $temuan->map(fn ($g) => "grup {$g}")->all(),
        );
    }

    /**
     * Split kosong atau terlalu kecil untuk berarti.
     *
     * Test set berisi tiga artikel tetap menghasilkan angka presisi, dan
     * angkanya akan terlihat sangat bagus atau sangat buruk tanpa keduanya
     * bermakna apa pun.
     */
    private function splitKosong(SnapshotDatasetRelevansi $snapshot): ?array
    {
        $kurang = [];

        foreach (['train' => 50, 'validation' => 20, 'test' => 20] as $split => $minimal) {
            $jumlah = $snapshot->item()->where('split', $split)->count();

            if ($jumlah < $minimal) {
                $kurang[] = "{$split} hanya {$jumlah}, minimal {$minimal}";
            }
        }

        return $this->laporan(
            'split_terlalu_kecil',
            'Ada split yang terlalu kecil untuk menghasilkan angka yang bermakna.',
            $kurang,
        );
    }

    /**
     * @param  list<string>  $contoh
     * @return array{jenis: string, keterangan: string, jumlah: int, contoh: list<string>}|null
     */
    private function laporan(string $jenis, string $keterangan, array $contoh): ?array
    {
        if ($contoh === []) {
            return null;
        }

        return [
            'jenis' => $jenis,
            'keterangan' => $keterangan,
            'jumlah' => count($contoh),
            // Cukup lima. Daftar penuh berisi ribuan baris tidak membantu
            // siapa pun memperbaikinya, dan yang dibutuhkan admin adalah tahu
            // jenis masalahnya lalu membuka datasetnya.
            'contoh' => array_slice($contoh, 0, 5),
        ];
    }
}
