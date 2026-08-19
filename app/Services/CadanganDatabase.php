<?php

namespace App\Services;

use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Pembuatan cadangan basis data.
 *
 * Dipakai dua pemanggil: tombol di halaman admin dan perintah terjadwal
 * `cadangan:buat` yang berjalan tiap Senin pukul 03.00 WITA. Keduanya harus
 * menghasilkan berkas yang identik dan tunduk pada aturan simpan yang sama,
 * jadi logikanya tinggal di satu tempat. Versi sebelumnya seluruhnya ada di
 * controller, dan penjadwal tidak punya cara memanggilnya tanpa menyalin
 * ulang perintah pg_dump beserta seluruh penanganan galatnya.
 *
 * Satu berkas per minggu, Senin sampai Minggu WITA. Cadangan baru membuang
 * cadangan lain dari minggu yang sama, jadi menekan tombol manual di hari Rabu
 * menimpa hasil jadwal Senin, bukan menumpuk di sebelahnya. Alasannya:
 * cadangan yang berguna adalah yang paling akhir, dan sepuluh salinan dari satu
 * minggu yang sama hanya memakan slot yang seharusnya diisi minggu lain.
 *
 * ponytail: pg_dump dijalankan langsung, bukan lewat antrean, dan itu punya
 * ceiling yang berbeda di dua jalur. Jadwal mingguan berjalan di proses
 * penjadwal tanpa siapa pun yang menunggu, jadi batas dua jam di sana longgar.
 * Tombol manual berjalan di dalam satu permintaan HTTP, dan di situ yang lebih
 * dulu menyerah adalah kesabaran admin serta batas waktu reverse proxy, bukan
 * PHP. Kalau basis datanya tumbuh sampai pg_dump lewat dari sekitar sepuluh
 * menit, pindahkan jalur manualnya ke antrean beserta satu tabel status,
 * jangan naikkan TIMEOUT_PERMINTAAN lagi.
 */
class CadanganDatabase
{
    /** Folder di dalam disk `local`, yaitu storage/app/private. */
    public const DIREKTORI = 'cadangan';

    /**
     * Pola nama berkas, sekaligus penjaga jalur.
     *
     * Nama berkas datang dari URL pada rute unduh dan hapus, jadi ia masukan
     * yang tidak dipercaya. Pola ini tidak memuat garis miring maupun titik
     * ganda, sehingga `../../.env` tidak akan pernah lolos ke `path()`.
     * Penanda `D` wajib: tanpa itu `$` masih menerima satu baris baru di
     * ujung, dan "berkas.sql.gz\n" akan dianggap sah.
     */
    public const POLA_NAMA = '/^simak-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/D';

    /**
     * Jumlah cadangan yang disimpan. Sisanya dibuang saat cadangan baru dibuat.
     *
     * Satu berkas per minggu, jadi sepuluh berarti riwayat sepuluh minggu ke
     * belakang. Folder yang bisa tumbuh tanpa batas adalah folder yang cepat
     * atau lambat memenuhi disk server, dan yang mati pertama kali bukan
     * halaman ini melainkan Postgres yang kehabisan ruang untuk WAL.
     */
    public const SIMPAN_TERAKHIR = 10;

    /**
     * Batas waktu pg_dump saat dipanggil tombol manual.
     *
     * Sepuluh menit, dan angka itu ditentukan oleh admin yang sedang menunggu
     * halaman menjawab, bukan oleh basis datanya. Jadwal mingguan memakai batas
     * sendiri lewat argumen `buat()`, karena tidak ada yang menunggunya.
     */
    public const TIMEOUT_PERMINTAAN = 600;

    /**
     * Batas waktu pg_dump saat dipanggil penjadwal.
     *
     * Dua jam. Pada mesin ini basis data 93 MB selesai dalam hitungan detik,
     * jadi angka ini bukan perkiraan durasi melainkan jaring pengaman untuk
     * keadaan yang benar benar macet, misalnya pg_dump yang menggantung
     * menunggu kunci tabel. Yang macet harus mati dan tercatat gagal, bukan
     * menduduki penjadwal sampai minggu berikutnya.
     */
    public const TIMEOUT_JADWAL = 7200;

    /**
     * Ruang kosong minimal, sebagai pecahan dari ukuran basis data.
     *
     * ponytail: heuristik, bukan hitungan. Dump teks yang dimampatkan gzip 9
     * pada basis data ini berukuran sekitar 13 persen dari ukuran basis
     * datanya (12 MB dari 93 MB), jadi 25 persen memberi ruang sekitar dua kali
     * lipat kebutuhan nyata. Ganti dengan pengukuran sungguhan kalau nanti
     * cadangan pernah gagal karena kehabisan ruang meski penjaga ini lolos.
     */
    private const RASIO_RUANG_MINIMAL = 0.25;

    /**
     * Membuat satu cadangan baru.
     *
     * Satu proses, bukan `pg_dump` lalu `gzip` terpisah, dan bukan pula pipa
     * `pg_dump | gzip`. Pipa membuang kode keluar pg_dump dan hanya melaporkan
     * kode keluar gzip, sehingga dump yang putus di tengah tetap menghasilkan
     * berkas .gz yang terlihat sah. Dua proses terpisah menghindari itu, tapi
     * harganya satu salinan dump mentah di /tmp, dan pada basis data besar
     * salinan itulah yang lebih dulu memenuhi disk. `--compress=9` milik
     * pg_dump menyelesaikan keduanya: kompresi terjadi di dalam proses yang
     * sama, kode keluarnya tetap milik pg_dump, dan tidak ada berkas mentah
     * yang pernah menyentuh disk.
     *
     * Hasilnya tetap gzip biasa, jadi perintah pemulihan tidak berubah:
     * `gunzip -c berkas.sql.gz | psql`.
     *
     * @param  int  $timeoutDetik  Batas waktu pg_dump, lihat kedua konstanta di atas.
     * @return array{nama: string, ditimpa: int, dibuang: int}
     *
     * @throws CadanganGagal
     */
    public function buat(int $timeoutDetik = self::TIMEOUT_PERMINTAAN): array
    {
        $db = $this->koneksi();

        if (($db['driver'] ?? null) !== 'pgsql') {
            throw new CadanganGagal(
                'Cadangan hanya mendukung PostgreSQL, sedangkan koneksi aktif memakai driver '.($db['driver'] ?? 'tidak dikenal').'.'
            );
        }

        if ($this->versiPgDump() === null) {
            throw new CadanganGagal(
                'Perintah pg_dump tidak ada di server ini.',
                'Pasang paket postgresql-client di image PHP, lalu bangun ulang container-nya'
            );
        }

        $this->periksaRuang();

        $disk = $this->disk();
        $disk->makeDirectory(self::DIREKTORI);

        // Detik ikut masuk nama berkas. Tanpa detik, dua pembuatan dalam satu
        // menit yang sama akan menimpa berkas pertama diam-diam, di luar
        // aturan timpa mingguan yang memang disengaja.
        $saat = CarbonImmutable::now(Waktu::ZONA);
        $nama = 'simak-'.$saat->format('Y-m-d-His').'.sql.gz';

        // Ditulis dengan nama kerja lebih dulu, lalu diganti nama setelah
        // pg_dump menjawab berhasil. Dump yang mati di tengah jalan, entah
        // karena batas waktu atau karena disk penuh, meninggalkan berkas
        // separuh jadi, dan berkas separuh jadi yang sudah bernama benar
        // adalah berkas yang terbaca sebagai cadangan sah sampai hari ia
        // dibutuhkan. Nama kerja diawali titik, jadi ia juga tidak pernah
        // lolos pola nama pada daftar arsip.
        $kerja = self::DIREKTORI.'/.kerja-'.$nama;

        $dump = Process::timeout($timeoutDetik)
            // Kata sandi lewat variabel lingkungan, bukan lewat argumen.
            // Argumen proses terbaca seluruh pengguna server lewat `ps`.
            ->env(['PGPASSWORD' => (string) ($db['password'] ?? '')])
            ->run(sprintf(
                'pg_dump --host=%s --port=%s --username=%s --dbname=%s --no-owner --no-privileges --clean --if-exists --compress=9 --file=%s',
                escapeshellarg((string) ($db['host'] ?? '')),
                escapeshellarg((string) ($db['port'] ?? '5432')),
                escapeshellarg((string) ($db['username'] ?? '')),
                escapeshellarg((string) ($db['database'] ?? '')),
                escapeshellarg($disk->path($kerja)),
            ));

        if ($dump->failed()) {
            $disk->delete($kerja);

            throw new CadanganGagal('pg_dump gagal.', $this->potong($dump->errorOutput() ?: $dump->output()));
        }

        $disk->move($kerja, self::DIREKTORI.'/'.$nama);

        // Urutannya penting. Timpa mingguan lebih dulu, baru pemangkasan slot,
        // supaya berkas yang baru saja dibuang minggu ini tidak ikut terhitung
        // sebagai isi slot yang membuat cadangan minggu terlama dibuang
        // padahal slotnya sebenarnya masih longgar.
        $ditimpa = $this->buangSatuMinggu($saat, $nama);

        return [
            'nama' => $nama,
            'ditimpa' => $ditimpa,
            'dibuang' => $this->pangkas(),
        ];
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
    public function daftar(): array
    {
        $disk = $this->disk();

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
     * Membuang cadangan lain dari minggu yang sama.
     *
     * Batas minggunya Senin 00.00 sampai Minggu 24.00 WITA, bukan tujuh hari ke
     * belakang dari saat ini. Bedanya nyata di hari Senin pagi: jadwal pukul
     * 03.00 tidak boleh menghapus cadangan Minggu malam yang masih milik minggu
     * sebelumnya, dan hitungan mundur tujuh hari akan menghapusnya.
     *
     * Waktu dibaca dari nama berkas, bukan dari `lastModified()`. Nama berkas
     * ditulis dalam WITA saat pembuatan dan tidak pernah berubah, sedangkan
     * waktu ubah berkas ikut bergeser saat berkas disalin antar server, dan
     * cadangan justru berkas yang sering disalin.
     *
     * @param  string  $kecuali  Nama berkas yang baru dibuat, yang justru harus bertahan.
     * @return int Jumlah berkas yang dibuang.
     */
    public function buangSatuMinggu(CarbonImmutable $saat, string $kecuali): int
    {
        $awal = $saat->setTimezone(Waktu::ZONA)->startOfWeek(CarbonInterface::MONDAY);
        $akhir = $awal->addWeek();

        $disk = $this->disk();
        $dibuang = 0;

        foreach ($this->daftar() as $berkas) {
            if ($berkas['nama'] === $kecuali) {
                continue;
            }

            $dibuat = $this->waktuDariNama($berkas['nama']);

            if ($dibuat >= $awal && $dibuat < $akhir) {
                $disk->delete(self::DIREKTORI.'/'.$berkas['nama']);
                $dibuang++;
            }
        }

        return $dibuang;
    }

    /**
     * Versi pg_dump, atau null kalau perintahnya tidak ada.
     *
     * Ini yang membedakan "tombolnya belum pernah ditekan" dari "tombolnya
     * tidak akan pernah bisa bekerja di server ini". Keduanya terlihat sama
     * pada halaman kosong, dan yang kedua hanya bisa dijawab dengan membangun
     * ulang image, bukan dengan menekan tombol lebih keras.
     */
    public function versiPgDump(): ?string
    {
        $hasil = Process::timeout(5)->run('pg_dump --version');

        return $hasil->successful() ? trim($hasil->output()) : null;
    }

    /** Ruang kosong disk tempat cadangan disimpan, null bila tidak terbaca. */
    public function ruangSisa(): ?int
    {
        $sisa = @disk_free_space($this->disk()->path(''));

        return is_float($sisa) ? (int) $sisa : null;
    }

    /**
     * Ukuran basis data menurut Postgres sendiri, null bila tidak terbaca.
     *
     * Dipakai dua hal: penjaga ruang disk sebelum dump, dan angka di halaman
     * kesiapan server. Keduanya butuh jawaban yang tumbuh bersama data, bukan
     * angka tetap yang ditulis saat fitur ini dibuat.
     */
    public function ukuranDatabase(): ?int
    {
        if (($this->koneksi()['driver'] ?? null) !== 'pgsql') {
            return null;
        }

        try {
            $baris = DB::selectOne('select pg_database_size(current_database()) as ukuran');
        } catch (QueryException) {
            // Basis data yang tidak bisa ditanya ukurannya bukan alasan untuk
            // menggagalkan halaman maupun cadangannya. Penjaga ruang di
            // `periksaRuang()` tinggal dilewati.
            return null;
        }

        return $baris === null ? null : (int) $baris->ukuran;
    }

    /** @return array<string, mixed> */
    public function koneksi(): array
    {
        return config('database.connections.'.config('database.default'), []);
    }

    /**
     * Menolak berjalan saat disk hampir penuh.
     *
     * Cadangan yang gagal di tengah jalan karena kehabisan ruang tidak berhenti
     * pada dirinya sendiri. Yang mati berikutnya adalah Postgres, yang butuh
     * ruang untuk WAL dan tidak punya tempat menulisnya, dan pemulihannya jauh
     * lebih mahal daripada satu minggu tanpa cadangan.
     *
     * @throws CadanganGagal
     */
    private function periksaRuang(): void
    {
        $ukuran = $this->ukuranDatabase();
        $sisa = $this->ruangSisa();

        if ($ukuran === null || $sisa === null) {
            return;
        }

        $minimal = (int) ceil($ukuran * self::RASIO_RUANG_MINIMAL);

        if ($sisa >= $minimal) {
            return;
        }

        throw new CadanganGagal(
            'Ruang disk tidak cukup untuk membuat cadangan.',
            'Sisa '.$this->bita($sisa).', dibutuhkan sekitar '.$this->bita($minimal).' untuk basis data '.$this->bita($ukuran).'. Hapus cadangan lama atau tambah kapasitas disk'
        );
    }

    /**
     * Membuang cadangan di luar batas simpan.
     *
     * @return int Jumlah berkas yang dibuang.
     */
    private function pangkas(): int
    {
        $disk = $this->disk();
        $berlebih = array_slice($this->daftar(), self::SIMPAN_TERAKHIR);

        foreach ($berlebih as $berkas) {
            $disk->delete(self::DIREKTORI.'/'.$berkas['nama']);
        }

        return count($berlebih);
    }

    /** Saat pembuatan menurut nama berkas, dalam WITA. */
    private function waktuDariNama(string $nama): CarbonImmutable
    {
        preg_match('/(\d{4}-\d{2}-\d{2}-\d{6})/', $nama, $cocok);

        return CarbonImmutable::createFromFormat('Y-m-d-His', $cocok[1], Waktu::ZONA);
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /** Bita dalam satuan yang terbaca sekilas, hanya untuk teks galat. */
    private function bita(int $nilai): string
    {
        $satuan = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pangkat = $nilai > 0 ? (int) min(floor(log($nilai, 1024)), count($satuan) - 1) : 0;

        return round($nilai / 1024 ** $pangkat, 1).' '.$satuan[$pangkat];
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
