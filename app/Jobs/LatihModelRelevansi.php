<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ItemSnapshotRelevansi;
use App\Models\PelatihanModelRelevansi;
use App\Services\ModelRelevansi\LayananRelevansi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Menunggui satu pelatihan IndoBERT di layanan Python.
 *
 * Job ini tidak melatih apa pun. Ia menyerahkan dataset, lalu menarik status
 * tiap beberapa detik dan menyalinnya ke barisnya sendiri sampai layanan
 * menyatakan selesai. Seluruh matematika ada di `relevansi/main.py`, dan
 * pembagian itu disengaja: yang berubah paling sering di sisi Laravel adalah
 * bentuk halaman, sedangkan yang berubah paling sering di sisi Python adalah
 * cara model dilatih.
 *
 * Berjalan di antrean `pelatihan` yang punya worker sendiri. Satu pelatihan di
 * CPU memakan belasan sampai puluhan menit, dan selama itu worker yang
 * mengerjakannya tidak menyentuh antrean lain. Kalau ia ikut antrean `default`,
 * crawler dan penilaian Gemini berhenti sepanjang pelatihan tanpa satu pun
 * galat yang menunjukkan sebabnya.
 *
 * `$tries = 1`. Pelatihan yang gagal tidak boleh diulang sendiri: penyebabnya
 * hampir selalu konfigurasi atau data, bukan gangguan sesaat, dan percobaan
 * kedua hanya menimpa pesan galat pertama dengan pesan yang sama dua puluh
 * menit kemudian.
 */
class LatihModelRelevansi implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Tujuh hari.
     *
     * Job menunggui layanan sepanjang pelatihan, jadi seluruh durasinya
     * terhitung sebagai satu pekerjaan yang berjalan. Batas ini harus lebih
     * kecil daripada `--timeout` worker `pelatihan` di docker-compose, kalau
     * tidak worker membunuh prosesnya lebih dulu dan barisnya tertinggal
     * berstatus berjalan selamanya.
     *
     * Angkanya sudah dinaikkan dua kali, dan keduanya karena alasan yang sama:
     * pelatihan nyata melewatinya. Enam jam kalah oleh checkpoint 24 layer pada
     * potongan 384 token, yang memakan dua setengah jam per epoch di CPU empat
     * thread. Dua belas jam cukup untuk snapshot 2.000 item, yang selesai dalam
     * 5 jam 45 menit, tetapi tidak untuk yang lebih besar.
     *
     * Tujuh hari dipilih supaya snapshot 10.000 item muat dengan kelonggaran
     * yang lebar. Pelatihan di CPU tumbuh kira-kira sebanding dengan jumlah
     * data, jadi 10.000 item berarti sekitar 29 jam. Batas yang hanya sedikit
     * di atas perkiraan itu akan kalah lagi begitu ada yang menambah epoch,
     * memanjangkan potongan token, atau melatih di mesin yang lebih sibuk.
     *
     * Batas yang kelewat longgar tidak berbahaya di sini, karena bukan ia yang
     * menghentikan pelatihan yang macet. Pelatihan yang salah dihentikan tombol
     * Batalkan, dan pelatihan yang layanannya mati berhenti sendiri lewat
     * `status()` yang memulangkan null. Batas waktu hanyalah jaring terakhir,
     * dan jaring terakhir yang terpasang terlalu tinggi jauh lebih murah
     * daripada yang memotong pekerjaan 29 jam pada jam kedua belas.
     */
    public int $timeout = 604800;

    public function __construct(public int $pelatihanId)
    {
        $this->onQueue('pelatihan');
    }

    public function handle(LayananRelevansi $layanan): void
    {
        $run = PelatihanModelRelevansi::find($this->pelatihanId);

        if ($run === null || $run->status !== 'menunggu') {
            return;
        }

        // Pembatalan yang ditekan selagi pekerjaan masih mengantre dihormati di
        // sini, sebelum satu byte pun dikirim ke layanan.
        if ($run->batal_diminta) {
            $run->update(['status' => 'dibatalkan', 'tahap' => 'Dibatalkan sebelum mulai', 'selesai_at' => now()]);

            return;
        }

        $run->update([
            'status' => 'berjalan',
            'tahap' => 'Menyiapkan dataset',
            'progres' => 1,
            'mulai_at' => now(),
            'galat' => null,
        ]);

        try {
            $this->jalankan($run, $layanan);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'gagal',
                'tahap' => 'Gagal',
                'galat' => $e->getMessage(),
                'selesai_at' => now(),
            ]);

            throw $e;
        }
    }

    private function jalankan(PelatihanModelRelevansi $run, LayananRelevansi $layanan): void
    {
        $bagian = $this->bagian($run);

        foreach (['train', 'test'] as $wajib) {
            if ($bagian[$wajib] === []) {
                throw new RuntimeException(
                    "Snapshot tidak punya data pada bagian {$wajib}. Buat snapshot baru dengan pembagian yang berbeda."
                );
            }
        }

        // Satu kelas saja di data latih menghasilkan model yang selalu menjawab
        // sama, dengan akurasi yang terlihat tinggi kalau test-nya ikut timpang.
        // Lebih baik berhenti di sini daripada menyerahkan angka semacam itu
        // setelah dua puluh menit.
        if (count(array_unique(array_column($bagian['train'], 'label'))) < 2) {
            throw new RuntimeException(
                'Data latih hanya berisi satu label. Ubah komposisi snapshot agar kedua label ikut terlatih.'
            );
        }

        $run->update(['tahap' => 'Mengirim dataset ke layanan model', 'progres' => 2]);

        $layanan->latih(
            $run->id,
            $run->konfigurasi,
            $bagian['train'],
            $bagian['validation'],
            $bagian['test'],
        );

        $this->tunggu($run, $layanan);
    }

    /**
     * Menarik status sampai layanan menyatakan selesai.
     *
     * Batal diperiksa dari database tiap putaran, bukan dari model di memori.
     * Tombol Batalkan menulis kolomnya dari permintaan HTTP yang sama sekali
     * lain, dan instance di worker tidak akan pernah tahu perubahannya kecuali
     * ditanyakan.
     */
    private function tunggu(PelatihanModelRelevansi $run, LayananRelevansi $layanan): void
    {
        $jeda = max(1, (int) config('relevansi.jeda_polling'));
        $sudahMintaBatal = false;

        while (true) {
            sleep($jeda);

            $kabar = $layanan->status($run->id);

            if ($kabar === null) {
                // Layanan hidup tetapi tidak mengenal run ini. Satu-satunya
                // sebab yang masuk akal adalah container-nya di-restart di
                // tengah pelatihan, dan statusnya memang hilang bersama proses.
                throw new RuntimeException(
                    'Layanan model kehilangan jejak pelatihan ini, kemungkinan container-nya di-restart. Jalankan ulang pelatihan.'
                );
            }

            $this->salin($run, $kabar);

            if ($kabar['selesai']) {
                $this->tutup($run, $kabar);

                return;
            }

            if (! $sudahMintaBatal && $this->batalDiminta($run)) {
                $sudahMintaBatal = true;
                $run->update(['tahap' => 'Menghentikan pelatihan']);
                $layanan->batal($run->id);
            }
        }
    }

    /**
     * Menyalin kabar dari layanan ke baris pelatihan.
     *
     * Kunci di balasan layanan sengaja dinamai sama persis dengan kolom di
     * sini, jadi yang terjadi memang penyalinan dan bukan penerjemahan. Daftar
     * kolomnya tetap ditulis eksplisit: `$kabar` datang dari luar aplikasi, dan
     * menyerahkannya utuh ke `update()` berarti layanan Python bisa menulis
     * kolom mana pun di tabel ini.
     *
     * @param  array<string, mixed>  $kabar
     */
    private function salin(PelatihanModelRelevansi $run, array $kabar): void
    {
        $run->update([
            'tahap' => $kabar['tahap'],
            'progres' => $kabar['progres'],
            'epoch_berjalan' => $kabar['epoch_berjalan'],
            'estimasi_sisa_detik' => $kabar['estimasi_sisa_detik'],
            'riwayat_epoch' => $kabar['riwayat_epoch'],
            'perangkat' => $kabar['perangkat'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $kabar
     */
    private function tutup(PelatihanModelRelevansi $run, array $kabar): void
    {
        $run->update([
            'status' => $kabar['status'],
            'metrik' => $kabar['metrik'],
            'confusion_matrix' => $kabar['confusion_matrix'],
            'laporan_klasifikasi' => $kabar['laporan_klasifikasi'],
            'artefak_path' => $kabar['artefak_path'],
            'galat' => $kabar['galat'],
            'selesai_at' => now(),
        ]);

        if ($kabar['status'] === 'berhasil') {
            $run->snapshot()->update(['status' => 'terpakai']);
        }
    }

    private function batalDiminta(PelatihanModelRelevansi $run): bool
    {
        return (bool) DB::table('pelatihan_model_relevansi')
            ->where('id', $run->id)
            ->value('batal_diminta');
    }

    /**
     * Isi snapshot per bagian.
     *
     * Judul digabung ke isi, bukan dikirim sebagai kolom terpisah. Judul berita
     * adalah kalimat paling padat sinyal di seluruh artikel, dan menaruhnya di
     * depan membuatnya selalu ikut terbaca meski isinya dipotong oleh maximum
     * sequence length. IndoBERT membaca 256 token pertama pada setelan bawaan,
     * dan itu jauh lebih pendek daripada satu berita utuh.
     *
     * @return array{train: list<array{teks: string, label: string}>, validation: list<array{teks: string, label: string}>, test: list<array{teks: string, label: string}>}
     */
    private function bagian(PelatihanModelRelevansi $run): array
    {
        $bagian = ['train' => [], 'validation' => [], 'test' => []];

        ItemSnapshotRelevansi::query()
            ->where('snapshot_dataset_relevansi_id', $run->snapshot_dataset_relevansi_id)
            ->orderBy('id')
            ->chunk(500, function ($baris) use (&$bagian) {
                foreach ($baris as $item) {
                    $bagian[$item->split][] = [
                        'teks' => $item->judul."\n".$item->isi,
                        'label' => $item->label,
                    ];
                }
            });

        return $bagian;
    }

    /**
     * Dipanggil saat job mati tanpa sempat menangkap galatnya sendiri, misalnya
     * karena kehabisan waktu.
     *
     * Tanpa ini, barisnya berhenti berstatus `berjalan` selamanya dan halaman
     * pemantauan menampilkan pelatihan yang sebenarnya sudah tidak ada.
     *
     * Layanan model ikut diberi tahu. Yang mati di sini hanya penunggunya, dan
     * proses pelatihan di container Python tidak tahu apa-apa tentang batas
     * waktu antrean: ia terus memakan seluruh jatah thread-nya berjam-jam
     * untuk hasil yang tidak akan pernah dibaca siapa pun, sekaligus menahan
     * kunci satu-pelatihan-pada-satu-waktu sehingga percobaan berikutnya
     * ditolak dengan galat yang menyebut pelatihan lain masih berjalan.
     */
    public function failed(?Throwable $e): void
    {
        app(LayananRelevansi::class)->batal($this->pelatihanId);

        PelatihanModelRelevansi::where('id', $this->pelatihanId)
            ->whereIn('status', ['menunggu', 'berjalan'])
            ->update([
                'status' => 'gagal',
                'tahap' => 'Gagal',
                'galat' => $e?->getMessage() ?? 'Pelatihan berhenti tanpa keterangan, kemungkinan kehabisan waktu.',
                'selesai_at' => now(),
            ]);
    }
}
