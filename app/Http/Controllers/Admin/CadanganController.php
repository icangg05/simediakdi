<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CadanganDatabase;
use App\Services\CadanganGagal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Halaman cadangan database.
 *
 * Seluruh pekerjaan nyatanya ada di App\Services\CadanganDatabase, karena
 * penjadwal mingguan memanggil jalur yang sama lewat perintah `cadangan:buat`.
 * Yang tersisa di sini hanya urusan HTTP: peran, penjaga nama berkas dari URL,
 * dan penerjemahan hasil menjadi kalimat di layar.
 *
 * Pembuatannya dijalankan langsung di dalam permintaan, bukan dilempar ke
 * antrean seperti crawl manual. Bedanya nyata: crawl mengunduh 28 feed dari
 * server milik orang lain dan makan sekitar dua menit, sedangkan pg_dump
 * membaca basis data di jaringan yang sama dan selesai dalam hitungan detik
 * pada kapasitas rancangan (300 artikel per hari). Menjalankannya langsung
 * berarti admin tahu hasilnya sebelum halaman selesai dimuat ulang, tanpa
 * perlu satu tabel status baru untuk melacak pekerjaan yang tidak kelihatan.
 */
class CadanganController extends Controller
{
    public function __construct(private readonly CadanganDatabase $cadangan) {}

    public function index(): Response
    {
        $db = $this->cadangan->koneksi();
        $berkas = $this->cadangan->daftar();

        return Inertia::render('admin/Cadangan', [
            'berkas' => $berkas,
            'total' => [
                'jumlah' => count($berkas),
                'ukuran' => array_sum(array_column($berkas, 'ukuran')),
            ],
            'database' => [
                'nama' => (string) ($db['database'] ?? ''),
                'host' => (string) ($db['host'] ?? ''),
                // Ikut dikirim supaya perintah pemulihan di layar tidak
                // menuliskan nama pengguna secara tetap. Versi sebelumnya
                // mengetiknya di dalam template Vue, dan perintah itu langsung
                // salah pada hari nama penggunanya berganti.
                'pengguna' => (string) ($db['username'] ?? ''),
                'driver' => (string) ($db['driver'] ?? ''),
                'didukung' => ($db['driver'] ?? null) === 'pgsql',
            ],
            // Null berarti pg_dump tidak ada di image. Itu keadaan yang harus
            // terbaca di layar sebagai kalimat, bukan sebagai tombol yang
            // selalu gagal tanpa pernah menyebut alasannya.
            'versiPgDump' => $this->cadangan->versiPgDump(),
            'ruangSisa' => $this->cadangan->ruangSisa(),
            // Ukuran basis data ditampilkan berdampingan dengan ruang disk,
            // karena keduanya baru berarti kalau dibandingkan. Angka sisa disk
            // sendirian tidak menjawab pertanyaan yang sebenarnya, yaitu
            // apakah cadangan berikutnya masih muat.
            'ukuranDatabase' => $this->cadangan->ukuranDatabase(),
            'simpanTerakhir' => CadanganDatabase::SIMPAN_TERAKHIR,
        ]);
    }

    public function buat(): RedirectResponse
    {
        try {
            $hasil = $this->cadangan->buat();
        } catch (CadanganGagal $e) {
            return back()->with('galat', $e->getMessage())->with('catatan', $e->catatan);
        }

        return back()->with('sukses', 'Cadangan '.$hasil['nama'].' selesai dibuat.')
            ->with('catatan', $this->catatanHasil($hasil));
    }

    public function unduh(string $nama): BinaryFileResponse
    {
        $jalur = $this->jalurSah($nama);

        return response()->download(Storage::disk('local')->path($jalur), $nama, [
            'Content-Type' => 'application/gzip',
        ]);
    }

    public function hapus(string $nama): RedirectResponse
    {
        Storage::disk('local')->delete($this->jalurSah($nama));

        return back()->with('sukses', 'Cadangan '.$nama.' dihapus.');
    }

    /**
     * Baris kecil di bawah pesan berhasil.
     *
     * Timpa mingguan disebut lebih dulu karena ia satu satunya kejadian yang
     * bisa mengejutkan: admin menekan tombol lalu mendapati jumlah berkasnya
     * tidak bertambah. Yang tidak mengejutkan tidak perlu diberi kalimat.
     *
     * @param  array{nama: string, ditimpa: int, dibuang: int}  $hasil
     */
    private function catatanHasil(array $hasil): string
    {
        if ($hasil['ditimpa'] > 0) {
            return 'Cadangan minggu ini yang lama ditimpa, satu berkas per minggu';
        }

        if ($hasil['dibuang'] > 0) {
            return $hasil['dibuang'].' cadangan terlama dibuang supaya jumlahnya tetap '.CadanganDatabase::SIMPAN_TERAKHIR;
        }

        return 'Unduh berkasnya dan simpan di luar server ini';
    }

    /**
     * Jalur relatif yang sudah lolos pola nama dan benar-benar ada.
     *
     * Dipakai rute unduh dan rute hapus. Keduanya menerima nama berkas dari
     * URL, jadi keduanya harus melewati penjaga yang sama persis. Satu salinan
     * penjaga per rute adalah satu salinan yang cepat atau lambat tertinggal.
     */
    private function jalurSah(string $nama): string
    {
        abort_unless(preg_match(CadanganDatabase::POLA_NAMA, $nama) === 1, 404);

        $jalur = CadanganDatabase::DIREKTORI.'/'.$nama;

        abort_unless(Storage::disk('local')->exists($jalur), 404);

        return $jalur;
    }
}
