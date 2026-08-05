<?php

namespace App\Services\Relevance;

use App\Enums\StatusLabelRelevansi;
use App\Models\ItemSnapshotDatasetRelevansi;
use App\Models\SampelRelevansi;
use App\Models\SnapshotDatasetRelevansi;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Membekukan susunan dataset untuk satu eksperimen. Dokumen 10 bagian 9.
 *
 * Dua aturan yang menentukan benar tidaknya seluruh angka evaluasi nanti, dan
 * keduanya mudah dilanggar tanpa terlihat:
 *
 * 1. **Pembagian dilakukan per grup duplikat, bukan per baris.** Rilis Antara
 *    yang sama muncul di sepuluh media, dan kalau satu salinannya masuk train
 *    sementara salinan lain masuk test, model diuji dengan artikel yang sudah
 *    pernah dilihatnya. Angkanya naik, dan naiknya bohong.
 * 2. **Label ikut disalin ke tiap item.** Dataset terus tumbuh dan label bisa
 *    dikoreksi kapan saja. Snapshot yang membaca label lewat join akan berubah
 *    artinya di belakang punggung, dan dua evaluasi atas snapshot yang sama
 *    memberi angka berbeda tanpa ada yang mengubah eksperimennya.
 *
 * ponytail: pembagiannya acak berstratifikasi, dijalankan langsung tanpa
 * antrean. Cukup untuk ribuan sampel; pindahkan ke job kalau datasetnya sudah
 * puluhan ribu, dan tambahkan split waktu kalau test perlu berisi artikel yang
 * lebih baru (dokumen 10 bagian 9.4 nomor 6).
 */
class RelevanceSnapshotService
{
    public function __construct(private RelevanceSplitValidator $validator) {}

    /**
     * @param  array{nama: string, deskripsi?: ?string, strategi_sampling?: string,
     *     random_seed?: int, versi_panduan_label?: string, persen_train?: int,
     *     persen_validation?: int, persen_test?: int}  $isian
     */
    public function buat(array $isian, User $pembuat): SnapshotDatasetRelevansi
    {
        $sampel = $this->sampelLayak();

        if ($sampel->isEmpty()) {
            throw new RuntimeException('Tidak ada sampel berlabel yang layak masuk snapshot.');
        }

        $porsi = $this->porsi($isian);
        $seed = $isian['random_seed'] ?? 42;

        $pembagian = $this->bagi($sampel, $isian['strategi_sampling'] ?? 'natural_distribution', $porsi, $seed);

        return DB::transaction(function () use ($isian, $pembuat, $seed, $pembagian) {
            $snapshot = SnapshotDatasetRelevansi::create([
                'nama' => $isian['nama'],
                'versi' => $this->versiBerikutnya($isian['nama']),
                'deskripsi' => $isian['deskripsi'] ?? null,
                'status' => 'draft',
                'strategi_sampling' => $isian['strategi_sampling'] ?? 'natural_distribution',
                'random_seed' => $seed,
                'versi_panduan_label' => $isian['versi_panduan_label'] ?? '2.1',
                'created_by' => $pembuat->id,
            ]);

            $this->simpanItem($snapshot, $pembagian);
            $this->hitungTotal($snapshot);

            return $snapshot->fresh();
        });
    }

    /**
     * Mengunci snapshot supaya susunannya tidak bisa berubah lagi.
     *
     * Kebocoran diperiksa di sini, bukan hanya saat menampilkan laporan.
     * Pemeriksaan yang cuma dipakai menggambar layar akan terlewat pada hari
     * seseorang mengunci lewat perintah artisan.
     */
    public function kunci(SnapshotDatasetRelevansi $snapshot, User $pengunci): SnapshotDatasetRelevansi
    {
        if ($snapshot->terkunci()) {
            return $snapshot;
        }

        $temuan = $this->validator->periksa($snapshot);

        if ($temuan !== []) {
            throw new RuntimeException(
                'Snapshot tidak bisa dikunci karena masih ada kebocoran: '.count($temuan).' temuan.'
            );
        }

        $snapshot->update([
            'status' => 'locked',
            'manifest_hash' => $this->manifestHash($snapshot),
            'locked_by' => $pengunci->id,
            'locked_at' => now(),
        ]);

        // Anggota test dikunci di tingkat sampel juga, supaya pelabelan biasa
        // tidak bisa mengubahnya tanpa alasan. Dokumen 10 bagian 9.6.
        SampelRelevansi::whereIn('id', $snapshot->item()->where('split', 'test')->pluck('sampel_relevansi_id'))
            ->update(['status_label' => 'terkunci_test']);

        return $snapshot->fresh();
    }

    /**
     * Cap jari isi snapshot, untuk membuktikan dua eksperimen memakai data yang
     * benar-benar sama. Diurutkan lebih dulu supaya urutan baris dari database
     * tidak ikut mengubah hasilnya.
     */
    public function manifestHash(SnapshotDatasetRelevansi $snapshot): string
    {
        $baris = $snapshot->item()
            ->orderBy('sampel_relevansi_id')
            ->get(['sampel_relevansi_id', 'split', 'label_at_snapshot', 'content_hash'])
            ->map(fn ($i) => implode(':', [
                $i->sampel_relevansi_id,
                $i->split,
                $i->label_at_snapshot->value,
                $i->content_hash,
            ]))
            ->implode("\n");

        return hash('sha256', $baris);
    }

    /** @return Collection<int, SampelRelevansi> */
    private function sampelLayak(): Collection
    {
        return SampelRelevansi::layakLatih()
            ->get(['id', 'judul', 'isi', 'url', 'label_manual', 'status_label', 'duplicate_group_id', 'tingkat_kesulitan']);
    }

    /** @return array{train: int, validation: int, test: int} */
    private function porsi(array $isian): array
    {
        $porsi = [
            'train' => $isian['persen_train'] ?? 80,
            'validation' => $isian['persen_validation'] ?? 10,
            'test' => $isian['persen_test'] ?? 10,
        ];

        if (array_sum($porsi) !== 100) {
            throw new RuntimeException('Persentase train, validation, dan test harus berjumlah 100.');
        }

        return $porsi;
    }

    /**
     * Membagi grup duplikat ke tiga split, berstratifikasi per label.
     *
     * Stratifikasi bukan kemewahan. Dengan 500 sampel, pembagian acak murni
     * bisa menghasilkan test set yang isinya 70% satu kelas, dan presisi yang
     * diukur di sana tidak menggambarkan apa pun kecuali keberuntungan
     * pengacakan.
     *
     * @param  Collection<int, SampelRelevansi>  $sampel
     * @param  array{train: int, validation: int, test: int}  $porsi
     * @return array<string, list<SampelRelevansi>>
     */
    private function bagi(Collection $sampel, string $strategi, array $porsi, int $seed): array
    {
        $grup = $sampel->groupBy(fn (SampelRelevansi $s) => $s->duplicate_group_id ?? $s->id);

        $hasil = ['train' => [], 'validation' => [], 'test' => []];

        // Sampel yang sudah pernah jadi anggota test tetap di test, selamanya.
        //
        // Tanpa aturan ini pembagian diacak ulang dari nol tiap snapshot, dan
        // F1 model v1 dan v2 diukur dengan penggaris yang berbeda: kenaikan
        // angka tidak bisa dibedakan dari test set yang kebetulan lebih mudah.
        //
        // Ongkosnya nyata dan disengaja: sampel test tidak pernah ikut melatih.
        // Test juga tidak bertambah sendiri, jadi pada dataset yang tumbuh
        // besar ia akan terasa kecil. Kalau itu terjadi, tambahkan anggota baru
        // sekali lalu kunci lagi, jangan mengacak ulang seluruhnya.
        [$grupTest, $grupBebas] = $grup->partition(
            fn (Collection $anggota) => $anggota->contains(
                fn (SampelRelevansi $s) => $s->status_label === StatusLabelRelevansi::TerkunciTest,
            ),
        );

        // Satu anggota terkunci menarik seluruh grup duplikatnya, termasuk
        // salinan yang baru dilabeli setelah snapshot lalu. Memisahkan grup
        // berarti menguji model dengan artikel yang sudah pernah dilihatnya.
        foreach ($grupTest as $anggota) {
            foreach ($anggota as $satu) {
                $hasil['test'][] = $satu;
            }
        }

        $adaTestTerkunci = $grupTest->isNotEmpty();

        if ($adaTestTerkunci) {
            // Jatah test sudah terisi, sisanya dibagi train dan validation
            // menurut perbandingan aslinya.
            $sisa = $porsi['train'] + $porsi['validation'];
            $porsi = [
                'train' => (int) round($porsi['train'] / $sisa * 100),
                'validation' => 100 - (int) round($porsi['train'] / $sisa * 100),
                'test' => 0,
            ];
        }

        // Label grup diambil dari anggota pertama. Grup dengan label campur
        // adalah tanda pelabelan tidak konsisten, dan validator yang
        // melaporkannya, bukan pembagi yang diam-diam memilih salah satu.
        $perLabel = $grupBebas->groupBy(fn (Collection $anggota) => $anggota->first()->label_manual->value);

        foreach ($perLabel as $daftarGrup) {
            $kunci = $daftarGrup->keys()->all();

            // Pengacakan berbenih, bukan shuffle biasa. Snapshot yang tidak
            // bisa dibuat ulang dengan benih yang sama bukan snapshot.
            mt_srand($seed);
            usort($kunci, fn ($a, $b) => mt_rand() <=> mt_rand());

            $jumlah = count($kunci);
            $batasTrain = (int) floor($jumlah * $porsi['train'] / 100);
            $batasValidation = $batasTrain + (int) floor($jumlah * $porsi['validation'] / 100);

            foreach ($kunci as $i => $kunciGrup) {
                // Sisa pembulatan jatuh ke train saat test sudah terkunci.
                // Tanpa cabang ini `floor` menyisakan beberapa grup yang
                // diam-diam masuk test, dan test berhenti sebanding dengan
                // milik snapshot sebelumnya.
                $split = match (true) {
                    $i < $batasTrain => 'train',
                    $i < $batasValidation => 'validation',
                    default => $adaTestTerkunci ? 'train' : 'test',
                };

                foreach ($daftarGrup[$kunciGrup] as $satu) {
                    $hasil[$split][] = $satu;
                }
            }
        }

        if ($strategi === 'balanced') {
            $hasil = $this->seimbangkan($hasil);
        }

        return $hasil;
    }

    /**
     * Menyamakan jumlah per kelas di data latih dengan membuang kelebihan.
     *
     * Hanya data latih yang disamakan. Validation dan test dibiarkan mengikuti
     * sebaran apa adanya, karena keduanya alat ukur: menyeimbangkannya berarti
     * mengukur model pada sebaran yang tidak pernah ditemuinya di produksi.
     *
     * @param  array<string, list<SampelRelevansi>>  $hasil
     * @return array<string, list<SampelRelevansi>>
     */
    private function seimbangkan(array $hasil): array
    {
        $perLabel = collect($hasil['train'])->groupBy(fn (SampelRelevansi $s) => $s->label_manual->value);
        $terkecil = $perLabel->min(fn (Collection $c) => $c->count()) ?? 0;

        $hasil['train'] = $perLabel
            ->flatMap(fn (Collection $c) => $c->take($terkecil))
            ->values()
            ->all();

        return $hasil;
    }

    /** @param array<string, list<SampelRelevansi>> $pembagian */
    private function simpanItem(SnapshotDatasetRelevansi $snapshot, array $pembagian): void
    {
        foreach ($pembagian as $split => $daftar) {
            $baris = array_map(fn (SampelRelevansi $s) => [
                'snapshot_dataset_relevansi_id' => $snapshot->id,
                'sampel_relevansi_id' => $s->id,
                'split' => $split,
                'duplicate_group_id' => $s->duplicate_group_id,
                'label_at_snapshot' => $s->label_manual->value,
                'content_hash' => $this->contentHash($s),
                'created_at' => now(),
            ], $daftar);

            foreach (array_chunk($baris, 500) as $potongan) {
                ItemSnapshotDatasetRelevansi::insert($potongan);
            }
        }
    }

    /**
     * Cap jari isi artikel, dinormalkan lebih dulu.
     *
     * Dipakai menemukan salinan yang lolos pengelompokan duplikat, misalnya
     * siaran pers yang dimuat dua media dengan judul berbeda. Spasi dan huruf
     * besar dibuang karena keduanya berbeda antar media tanpa mengubah isinya.
     */
    private function contentHash(SampelRelevansi $sampel): string
    {
        $teks = mb_strtolower($sampel->judul.' '.$sampel->isi);

        return hash('sha256', trim(preg_replace('/\s+/u', ' ', $teks)));
    }

    private function hitungTotal(SnapshotDatasetRelevansi $snapshot): void
    {
        $item = $snapshot->item();

        $snapshot->update([
            'total_relevan' => (clone $item)->where('label_at_snapshot', 'relevan')->count(),
            'total_tidak_relevan' => (clone $item)->where('label_at_snapshot', 'tidak_relevan')->count(),
            'total_train' => (clone $item)->where('split', 'train')->count(),
            'total_validation' => (clone $item)->where('split', 'validation')->count(),
            'total_test' => (clone $item)->where('split', 'test')->count(),
        ]);
    }

    private function versiBerikutnya(string $nama): string
    {
        return 'v'.(SnapshotDatasetRelevansi::where('nama', $nama)->count() + 1);
    }
}
