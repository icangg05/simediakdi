<?php

declare(strict_types=1);

namespace App\Services\ModelRelevansi;

use App\Models\ItemSnapshotRelevansi;
use App\Models\SnapshotDatasetRelevansi;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Kandidat dataset dan pembekuannya menjadi snapshot.
 *
 * Kandidat hanyalah artikel yang sudah dinilai Gemini dan keputusannya sudah
 * pasti. Artikel yang belum dinilai tidak punya label untuk dilatihkan, dan
 * artikel yang berstatus `perlu_review` punya sesuatu yang lebih berbahaya
 * daripada tidak punya label: ia menyimpan `relevan = false` di database
 * padahal artinya "Gemini tidak mau memutuskan", bukan "tidak relevan".
 * Memasukkannya berarti mengajari model bahwa keraguan sama dengan penolakan.
 */
class KandidatDataset
{
    /** Isi artikel yang lebih pendek dari ini tidak cukup untuk dilatihkan. */
    private const MIN_PANJANG_ISI = 200;

    /**
     * Jumlah kandidat per label beserta persentasenya.
     *
     * @return array<string, mixed>
     */
    public function ringkasan(): array
    {
        $baris = $this->kueri()
            ->selectRaw('s.relevan, count(*) as jumlah')
            ->groupBy('s.relevan')
            ->pluck('jumlah', 'relevan');

        // Postgres mengembalikan boolean, tetapi pluck() memakainya sebagai
        // kunci array sehingga true menjadi 1 dan false menjadi 0. Membaca
        // keduanya lewat cast eksplisit lebih jelas daripada bergantung pada
        // bentuk kunci yang berubah antar driver.
        $relevan = (int) ($baris[1] ?? $baris[true] ?? 0);
        $tidakRelevan = (int) ($baris[0] ?? $baris[false] ?? 0);
        $total = $relevan + $tidakRelevan;

        return [
            'total' => $total,
            'relevan' => $relevan,
            'tidak_relevan' => $tidakRelevan,
            'persen_relevan' => $total === 0 ? 0.0 : round($relevan / $total * 100, 1),
            'persen_tidak_relevan' => $total === 0 ? 0.0 : round($tidakRelevan / $total * 100, 1),
            'min_panjang_isi' => self::MIN_PANJANG_ISI,
        ];
    }

    /**
     * Membekukan satu snapshot beserta seluruh isinya.
     *
     * Seluruhnya dalam satu transaksi. Snapshot yang barisnya masuk separuh lalu
     * gagal akan tetap terlihat sebagai snapshot yang siap dipakai di layar,
     * lengkap dengan angka total yang tidak cocok dengan isinya.
     *
     * @param  array<string, mixed>  $data
     */
    public function buat(array $data, User $pembuat): SnapshotDatasetRelevansi
    {
        $seed = (int) $data['random_seed'];
        $total = (int) $data['jumlah_total'];

        $jumlah = [
            'relevan' => (int) round($total * (int) $data['persen_relevan'] / 100),
            'tidak_relevan' => 0,
        ];
        // Sisanya, bukan hasil pembulatan kedua. Dua pembulatan yang dijumlahkan
        // bisa meleset satu dari total yang diminta, dan selisih satu baris itu
        // membuat angka di layar tidak cocok dengan isi tabel.
        $jumlah['tidak_relevan'] = $total - $jumlah['relevan'];

        return DB::transaction(function () use ($data, $pembuat, $seed, $total, $jumlah) {
            $snapshot = SnapshotDatasetRelevansi::create([
                'nama' => $data['nama'],
                'deskripsi' => $data['deskripsi'] ?? null,
                'random_seed' => $seed,
                'persen_relevan' => $data['persen_relevan'],
                'persen_tidak_relevan' => $data['persen_tidak_relevan'],
                'persen_train' => $data['persen_train'],
                'persen_validation' => $data['persen_validation'],
                'persen_test' => $data['persen_test'],
                'dibuat_oleh' => $pembuat->id,
            ]);

            $hitungSplit = ['train' => 0, 'validation' => 0, 'test' => 0];

            foreach (['relevan', 'tidak_relevan'] as $label) {
                $terpilih = $this->ambilAcak($label === 'relevan', $jumlah[$label], $seed);

                $bagian = $this->bagi(
                    count($terpilih),
                    (int) $data['persen_train'],
                    (int) $data['persen_validation'],
                    (int) $data['persen_test'],
                );

                $mulai = 0;

                foreach ($bagian as $split => $n) {
                    $hitungSplit[$split] += $n;

                    foreach (array_slice($terpilih, $mulai, $n) as $artikel) {
                        ItemSnapshotRelevansi::create([
                            'snapshot_dataset_relevansi_id' => $snapshot->id,
                            'artikel_id' => $artikel->id,
                            'judul' => $artikel->judul,
                            'isi' => $artikel->isi,
                            'label' => $label,
                            'split' => $split,
                        ]);
                    }

                    $mulai += $n;
                }
            }

            $snapshot->update([
                'total' => $total,
                'total_relevan' => $jumlah['relevan'],
                'total_tidak_relevan' => $jumlah['tidak_relevan'],
                'total_train' => $hitungSplit['train'],
                'total_validation' => $hitungSplit['validation'],
                'total_test' => $hitungSplit['test'],
            ]);

            return $snapshot->fresh();
        });
    }

    /**
     * Mengambil sejumlah artikel satu label secara acak tetapi bisa diulang.
     *
     * Pengacakan dilakukan di PHP, bukan lewat ORDER BY random() di Postgres.
     * Postgres punya setseed(), tetapi ia berlaku per sesi dan urutannya bisa
     * berbeda antar versi server. Seed yang tidak menghasilkan susunan yang sama
     * pada mesin lain adalah seed yang tidak mereproduksi apa pun.
     *
     * @return list<object{id: int, judul: string, isi: string}>
     */
    private function ambilAcak(bool $relevan, int $jumlah, int $seed): array
    {
        if ($jumlah <= 0) {
            return [];
        }

        // Id dulu, teks belakangan. Menarik isi seluruh kandidat lalu membuang
        // sebagian besarnya berarti memuat puluhan megabita teks ke memori
        // untuk mengambil beberapa ratus baris.
        $id = $this->kueri()
            ->where('s.relevan', $relevan)
            ->orderBy('artikel.id')
            ->pluck('artikel.id')
            ->all();

        // Seed digeser per label supaya dua label tidak memakai urutan acak yang
        // sama. Tanpa pergeseran, keduanya mengambil posisi indeks yang persis
        // sama dari daftarnya masing-masing.
        mt_srand($seed + ($relevan ? 0 : 1));

        for ($i = count($id) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$id[$i], $id[$j]] = [$id[$j], $id[$i]];
        }

        $terpilih = array_slice($id, 0, $jumlah);

        $baris = DB::table('artikel')
            ->select('id', 'judul', 'isi')
            ->whereIn('id', $terpilih)
            ->get()
            ->keyBy('id');

        // Urutan hasil `whereIn` tidak mengikuti urutan yang diminta, dan urutan
        // itulah yang menentukan siapa masuk train dan siapa masuk test.
        return array_values(array_filter(array_map(
            fn (int $i) => $baris->get($i),
            $terpilih,
        )));
    }

    /**
     * Membagi satu label menjadi tiga bagian yang jumlahnya persis kembali utuh.
     *
     * Sisa pembulatan diberikan ke train, bukan dibiarkan hilang. Train adalah
     * bagian terbesar, jadi selisih satu dua baris di sana tidak menggeser arti
     * apa pun, sedangkan test yang kekurangan baris menggeser angka evaluasi.
     *
     * @return array{train: int, validation: int, test: int}
     */
    private function bagi(int $total, int $persenTrain, int $persenValidation, int $persenTest): array
    {
        $validation = (int) floor($total * $persenValidation / 100);
        $test = (int) floor($total * $persenTest / 100);

        return [
            'train' => $total - $validation - $test,
            'validation' => $validation,
            'test' => $test,
        ];
    }

    /**
     * Satu definisi kandidat, dipakai penghitungan maupun pengambilan.
     *
     * Sengaja query builder, bukan Eloquent. Model Artikel membawa scope global
     * MilikMedia, dan halaman ini memang harus melihat seluruh artikel apa pun
     * medianya. Menuliskannya sebagai `withoutGlobalScope` di setiap pemanggil
     * adalah satu baris yang bisa lupa ditulis.
     */
    private function kueri(): Builder
    {
        return DB::table('artikel')
            ->join('analisis_sentimen as s', 's.artikel_id', '=', 'artikel.id')
            // Dua status ini yang berarti Gemini sudah selesai dan jawabannya
            // pasti. `perlu_review` sengaja tidak ikut.
            ->whereIn('artikel.status_proses', ['selesai', 'tidak_relevan'])
            ->whereNotNull('s.provider')
            ->whereNotNull('artikel.isi')
            ->whereRaw('length(btrim(artikel.isi)) >= ?', [self::MIN_PANJANG_ISI]);
    }
}
