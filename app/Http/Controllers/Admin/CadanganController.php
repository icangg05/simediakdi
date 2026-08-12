<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cadangan database (backup manual).
 *
 * Satu berkas SQL terkompresi per penekanan tombol, disimpan di disk privat
 * dan diunduh admin sendiri. Tidak ada unggahan ke penyimpanan luar, karena
 * isinya seluruh basis data termasuk kredensial media partner dan data
 * pelaporan yang belum diverifikasi.
 *
 * Pekerjaannya dijalankan langsung di dalam permintaan, bukan dilempar ke
 * antrean seperti crawl manual. Bedanya nyata: crawl mengunduh 28 feed dari
 * server milik orang lain dan makan sekitar dua menit, sedangkan pg_dump
 * membaca basis data di jaringan yang sama dan selesai dalam hitungan detik
 * pada kapasitas rancangan (300 artikel per hari). Menjalankannya langsung
 * berarti admin tahu hasilnya sebelum halaman selesai dimuat ulang, tanpa
 * perlu satu tabel status baru untuk melacak pekerjaan yang tidak kelihatan.
 *
 * ponytail: batas atasnya adalah timeout PHP. Kalau basis datanya tumbuh
 * sampai pg_dump melewati sekitar sepuluh menit, pindahkan ke antrean beserta
 * satu tabel status, jangan naikkan timeout-nya lagi.
 */
class CadanganController extends Controller
{
    /** Folder di dalam disk `local`, yaitu storage/app/private. */
    private const DIREKTORI = 'cadangan';

    /**
     * Pola nama berkas, sekaligus penjaga jalur.
     *
     * Nama berkas datang dari URL pada rute unduh dan hapus, jadi ia masukan
     * yang tidak dipercaya. Pola ini tidak memuat garis miring maupun titik
     * ganda, sehingga `../../.env` tidak akan pernah lolos ke `path()`.
     * Penanda `D` wajib: tanpa itu `$` masih menerima satu baris baru di
     * ujung, dan "berkas.sql.gz\n" akan dianggap sah.
     */
    private const POLA_NAMA = '/^simedia-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/D';

    /**
     * Jumlah cadangan yang disimpan. Sisanya dibuang saat cadangan baru dibuat.
     *
     * Halaman yang bisa menulis berkas tanpa batas adalah halaman yang cepat
     * atau lambat memenuhi disk server, dan yang mati pertama kali bukan
     * halaman ini melainkan Postgres yang kehabisan ruang untuk WAL.
     */
    private const SIMPAN_TERAKHIR = 10;

    /** Batas waktu satu proses. Lihat catatan ponytail di kepala kelas. */
    private const TIMEOUT_DETIK = 600;

    public function index(): Response
    {
        $db = $this->koneksi();
        $berkas = $this->daftar();

        return Inertia::render('admin/Cadangan', [
            'berkas' => $berkas,
            'total' => [
                'jumlah' => count($berkas),
                'ukuran' => array_sum(array_column($berkas, 'ukuran')),
            ],
            'database' => [
                'nama' => (string) ($db['database'] ?? ''),
                'host' => (string) ($db['host'] ?? ''),
                'driver' => (string) ($db['driver'] ?? ''),
                'didukung' => ($db['driver'] ?? null) === 'pgsql',
            ],
            // Null berarti pg_dump tidak ada di image. Itu keadaan yang harus
            // terbaca di layar sebagai kalimat, bukan sebagai tombol yang
            // selalu gagal tanpa pernah menyebut alasannya.
            'versiPgDump' => $this->versiPgDump(),
            'ruangSisa' => $this->ruangSisa(),
            'simpanTerakhir' => self::SIMPAN_TERAKHIR,
        ]);
    }

    /**
     * Membuat satu cadangan baru.
     *
     * Dua proses, bukan satu pipa `pg_dump | gzip`. Alasannya kode keluar:
     * pada pipa, yang dilaporkan shell adalah kode keluar gzip, sehingga
     * pg_dump yang gagal di tengah jalan tetap menghasilkan berkas .gz yang
     * terlihat sah dan baru ketahuan rusak saat dipulihkan. Dua proses berarti
     * dua kode keluar yang bisa diperiksa masing-masing.
     */
    public function buat(): RedirectResponse
    {
        $db = $this->koneksi();

        if (($db['driver'] ?? null) !== 'pgsql') {
            return back()->with('galat', 'Cadangan hanya mendukung PostgreSQL, sedangkan koneksi aktif memakai driver '.($db['driver'] ?? 'tidak dikenal').'.');
        }

        if ($this->versiPgDump() === null) {
            return back()->with('galat', 'Perintah pg_dump tidak ada di server ini.')
                ->with('catatan', 'Pasang paket postgresql-client di image PHP, lalu bangun ulang container-nya');
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIREKTORI);

        // Detik ikut masuk nama berkas. Tanpa detik, dua penekanan tombol
        // dalam satu menit yang sama akan menimpa cadangan pertama diam-diam.
        $nama = 'simedia-'.CarbonImmutable::now(Waktu::ZONA)->format('Y-m-d-His').'.sql.gz';
        $sementara = tempnam(sys_get_temp_dir(), 'cadangan-');

        $dump = Process::timeout(self::TIMEOUT_DETIK)
            // Kata sandi lewat variabel lingkungan, bukan lewat argumen.
            // Argumen proses terbaca seluruh pengguna server lewat `ps`.
            ->env(['PGPASSWORD' => (string) ($db['password'] ?? '')])
            ->run(sprintf(
                'pg_dump --host=%s --port=%s --username=%s --dbname=%s --no-owner --no-privileges --clean --if-exists --file=%s',
                escapeshellarg((string) ($db['host'] ?? '')),
                escapeshellarg((string) ($db['port'] ?? '5432')),
                escapeshellarg((string) ($db['username'] ?? '')),
                escapeshellarg((string) ($db['database'] ?? '')),
                escapeshellarg($sementara),
            ));

        if ($dump->failed()) {
            @unlink($sementara);

            return back()->with('galat', 'pg_dump gagal.')
                ->with('catatan', $this->potong($dump->errorOutput() ?: $dump->output()));
        }

        $tujuan = $disk->path(self::DIREKTORI.'/'.$nama);

        // `-c` dengan pengalihan, bukan `gzip berkas.sql`. Berkas sumbernya ada
        // di /tmp dan tujuannya di storage, dan gzip biasa menulis hasilnya di
        // sebelah sumbernya lalu menghapus sumbernya.
        $gzip = Process::timeout(self::TIMEOUT_DETIK)
            ->run(sprintf('gzip -9 -c %s > %s', escapeshellarg($sementara), escapeshellarg($tujuan)));

        @unlink($sementara);

        if ($gzip->failed()) {
            @unlink($tujuan);

            return back()->with('galat', 'Kompresi gagal.')
                ->with('catatan', $this->potong($gzip->errorOutput() ?: $gzip->output()));
        }

        $dibuang = $this->pangkas();

        return back()->with('sukses', 'Cadangan '.$nama.' selesai dibuat.')
            ->with('catatan', $dibuang > 0
                ? $dibuang.' cadangan terlama dibuang supaya jumlahnya tetap '.self::SIMPAN_TERAKHIR
                : 'Unduh berkasnya dan simpan di luar server ini');
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
     * Jalur relatif yang sudah lolos pola nama dan benar-benar ada.
     *
     * Dipakai rute unduh dan rute hapus. Keduanya menerima nama berkas dari
     * URL, jadi keduanya harus melewati penjaga yang sama persis. Satu salinan
     * penjaga per rute adalah satu salinan yang cepat atau lambat tertinggal.
     */
    private function jalurSah(string $nama): string
    {
        abort_unless(preg_match(self::POLA_NAMA, $nama) === 1, 404);

        $jalur = self::DIREKTORI.'/'.$nama;

        abort_unless(Storage::disk('local')->exists($jalur), 404);

        return $jalur;
    }

    /**
     * Daftar cadangan, terbaru di atas.
     *
     * Disaring dengan pola nama yang sama dengan penjaga jalur, supaya berkas
     * lain yang kebetulan tersimpan di folder ini tidak ikut muncul sebagai
     * baris yang tombol unduhnya selalu 404.
     *
     * @return list<array{nama: string, ukuran: int, dibuat_at: string}>
     */
    private function daftar(): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIREKTORI))
            ->filter(fn (string $jalur) => preg_match(self::POLA_NAMA, basename($jalur)) === 1)
            ->map(fn (string $jalur) => [
                'nama' => basename($jalur),
                'ukuran' => $disk->size($jalur),
                // ISO UTC, sama seperti seluruh waktu lain yang dikirim ke
                // Inertia. Konversi ke WITA dikerjakan lapisan tampilan.
                'dibuat_at' => CarbonImmutable::createFromTimestamp($disk->lastModified($jalur))->toJSON(),
            ])
            ->sortByDesc('dibuat_at')
            ->values()
            ->all();
    }

    /**
     * Membuang cadangan di luar batas simpan.
     *
     * ponytail: pemangkasan menumpang pada tombol buat, bukan pada penjadwal.
     * Jumlah berkas hanya bertambah lewat tombol itu, jadi tidak ada keadaan
     * yang bisa melewati batas tanpa melewati method ini. Pindahkan ke
     * penjadwal kalau nanti ada jalur lain yang ikut menulis ke folder ini.
     *
     * @return int Jumlah berkas yang dibuang.
     */
    private function pangkas(): int
    {
        $disk = Storage::disk('local');
        $berlebih = array_slice($this->daftar(), self::SIMPAN_TERAKHIR);

        foreach ($berlebih as $berkas) {
            $disk->delete(self::DIREKTORI.'/'.$berkas['nama']);
        }

        return count($berlebih);
    }

    /** @return array<string, mixed> */
    private function koneksi(): array
    {
        return config('database.connections.'.config('database.default'), []);
    }

    /**
     * Versi pg_dump, atau null kalau perintahnya tidak ada.
     *
     * Ini yang membedakan "tombolnya belum pernah ditekan" dari "tombolnya
     * tidak akan pernah bisa bekerja di server ini". Keduanya terlihat sama
     * pada halaman kosong, dan yang kedua hanya bisa dijawab dengan membangun
     * ulang image, bukan dengan menekan tombol lebih keras.
     */
    private function versiPgDump(): ?string
    {
        $hasil = Process::timeout(5)->run('pg_dump --version');

        return $hasil->successful() ? trim($hasil->output()) : null;
    }

    /** Ruang kosong disk tempat cadangan disimpan, null bila tidak terbaca. */
    private function ruangSisa(): ?int
    {
        $sisa = @disk_free_space(Storage::disk('local')->path(''));

        return is_float($sisa) ? (int) $sisa : null;
    }

    /**
     * Keluaran galat dipotong sebelum masuk toast.
     *
     * pg_dump yang gagal karena sambungan putus bisa memuntahkan puluhan baris,
     * dan toast setinggi layar menutupi halaman yang sedang dibaca.
     */
    private function potong(string $pesan): string
    {
        return str($pesan)->trim()->limit(300)->value();
    }
}
