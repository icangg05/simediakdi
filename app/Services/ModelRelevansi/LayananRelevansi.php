<?php

declare(strict_types=1);

namespace App\Services\ModelRelevansi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Satu-satunya tempat aplikasi memanggil layanan IndoBERT.
 *
 * Melempar, bukan mengembalikan null. Ini kebalikan dari PenangkapLayar yang
 * memilih diam saat layanan arsip mati, dan bedanya disengaja: tangkapan layar
 * adalah pelengkap bukti yang boleh hilang, sedangkan pelatihan yang gagal
 * menghubungi layanan tidak punya jalan lain untuk diteruskan. Kegagalan yang
 * dibungkus menjadi null di sini akan muncul di layar sebagai pelatihan yang
 * berhenti pada nol persen tanpa keterangan apa pun.
 *
 * Daftar base model dan batas konfigurasi tinggal di kelas ini, bukan di
 * controller maupun di form. Keduanya membacanya dari sini lewat prop halaman,
 * jadi angka di kotak isian dan angka yang ditegakkan server tidak pernah bisa
 * berbeda.
 */
class LayananRelevansi
{
    /**
     * Checkpoint yang boleh dipakai beserta keterangannya untuk layar.
     *
     * Sengaja daftar tertutup, bukan kotak teks bebas. Nama checkpoint yang
     * salah ketik baru ketahuan setelah pekerjaan masuk antrean, gagal
     * mengunduh, dan menyisakan baris gagal dengan pesan dari Hugging Face
     * yang tidak menyebut bahwa yang keliru hanya satu huruf.
     */
    public const BASE_MODEL = [
        'indobenchmark/indobert-base-p1' => 'IndoBERT base, 12 layer. Setelan bawaan, seimbang antara mutu dan waktu.',
        'indobenchmark/indobert-lite-base-p1' => 'IndoBERT lite, jauh lebih ringan. Pilih ini kalau pelatihan di CPU terasa terlalu lama.',
        'apriandito/indobert-relevancy-classifier' => 'Sudah punya kepala relevansi, jadi ini melanjutkan pelatihan. Modelnya besar dan paling lambat di CPU.',
    ];

    /**
     * Batas dan nilai bawaan konfigurasi pelatihan.
     *
     * Angkanya khas fine-tuning transformer, bukan angka yang bisa dipindah
     * dari model lain. Learning rate 2e-5 dan tiga epoch adalah setelan yang
     * lazim untuk BERT pada dataset ribuan baris. Learning rate sebesar 0,5
     * yang wajar bagi regresi logistik akan merusak seluruh bobot pralatih
     * pada langkah pertama, dan hasilnya bukan galat melainkan model yang
     * menebak satu kelas untuk apa pun.
     */
    public const BATAS = [
        // Lebih dari sepuluh epoch pada dataset ribuan baris hampir selalu
        // menghafal, bukan belajar.
        'epoch' => ['min' => 1, 'maks' => 10, 'bawaan' => 3],
        // Batas atas 32 karena pelatihan berjalan di CPU dengan memori yang
        // dibagi bersama Postgres dan worker antrean.
        'batch_size' => ['min' => 1, 'maks' => 32, 'bawaan' => 8],
        'learning_rate' => ['min' => 0.000001, 'maks' => 0.0005, 'bawaan' => 0.00002],
        // BERT tidak bisa melampaui 512 token, batasnya ada di embedding posisi.
        'max_seq_length' => ['min' => 64, 'maks' => 512, 'bawaan' => 256],
        'early_stopping' => ['min' => 1, 'maks' => 5, 'bawaan' => 1],
    ];

    /**
     * Melempar satu pelatihan ke layanan. Balasannya datang seketika.
     *
     * @param  array<string, mixed>  $konfigurasi
     * @param  list<array{teks: string, label: string}>  $train
     * @param  list<array{teks: string, label: string}>  $validation
     * @param  list<array{teks: string, label: string}>  $test
     */
    public function latih(int $runId, array $konfigurasi, array $train, array $validation, array $test): void
    {
        $tanggapan = $this->kirim(
            (int) config('relevansi.timeout_kirim'),
            fn (PendingRequest $klien) => $klien->post('/latih', [
                'run_id' => $runId,
                'konfigurasi' => $konfigurasi,
                'train' => $train,
                'validation' => $validation,
                'test' => $test,
            ]),
        );

        if ($tanggapan->status() === 409) {
            throw new RuntimeException(
                'Masih ada pelatihan lain yang berjalan di layanan model. Tunggu sampai selesai atau batalkan lebih dulu.'
            );
        }

        if (! $tanggapan->successful()) {
            throw new RuntimeException($this->pesan($tanggapan->json(), 'Layanan model menolak permintaan pelatihan.'));
        }
    }

    /**
     * Keadaan satu pelatihan.
     *
     * Kuncinya sengaja sama persis dengan nama kolom di
     * `pelatihan_model_relevansi`, jadi pemanggilnya menyalin, bukan
     * menerjemahkan.
     *
     * @return array<string, mixed>|null null berarti layanan tidak mengenal run ini.
     */
    public function status(int $runId): ?array
    {
        $tanggapan = $this->kirim(
            (int) config('relevansi.timeout_status'),
            fn (PendingRequest $klien) => $klien->get("/status/{$runId}"),
        );

        if ($tanggapan->status() === 404) {
            return null;
        }

        if (! $tanggapan->successful()) {
            throw new RuntimeException('Layanan model tidak bisa dimintai status pelatihan.');
        }

        return $tanggapan->json();
    }

    /**
     * Menghentikan pelatihan di layanan.
     *
     * Mengembalikan false kalau tidak ada yang dihentikan, entah karena
     * layanan tidak mengenal run itu atau karena ia sudah selesai. Pemanggil
     * memakai jawaban itu untuk membedakan dua keadaan yang di database
     * terlihat sama persis: pelatihan yang benar-benar berjalan, dan baris yang
     * tertinggal berstatus berjalan setelah layanan di-restart.
     */
    public function batal(int $runId): bool
    {
        try {
            $tanggapan = $this->kirim(
                (int) config('relevansi.timeout_status'),
                fn (PendingRequest $klien) => $klien->post("/batal/{$runId}"),
            );
        } catch (RuntimeException) {
            // Layanan yang mati sudah menghentikan pelatihannya dengan cara
            // yang lebih tuntas daripada permintaan ini.
            return false;
        }

        return (bool) ($tanggapan->json()['dibatalkan'] ?? false);
    }

    /**
     * @return array{label: string, probabilitas_relevan: float, probabilitas_tidak_relevan: float, confidence: float, inferensi_ms: int}
     */
    public function prediksi(string $artefakPath, string $teks): array
    {
        $tanggapan = $this->kirim(
            (int) config('relevansi.timeout_prediksi'),
            fn (PendingRequest $klien) => $klien->post('/prediksi', [
                'artefak_path' => $artefakPath,
                'teks' => $teks,
            ]),
        );

        if (! $tanggapan->successful()) {
            throw new RuntimeException($this->pesan($tanggapan->json(), 'Layanan model gagal menilai teks ini.'));
        }

        return $tanggapan->json();
    }

    /**
     * Keadaan layanan untuk halaman Pengaturan dan tab Pelatihan.
     *
     * Mengembalikan null saat layanan mati, bukan melempar. Di sini memang
     * tidak ada pekerjaan yang bisa gagal: yang ditanyakan justru apakah
     * layanannya hidup.
     *
     * @return array<string, mixed>|null
     */
    public function kesehatan(): ?array
    {
        try {
            $tanggapan = Http::baseUrl((string) config('relevansi.base_url'))
                ->timeout(5)
                ->get('/health');
        } catch (ConnectionException) {
            return null;
        }

        return $tanggapan->successful() ? $tanggapan->json() : null;
    }

    /**
     * Pembungkus tunggal seluruh panggilan.
     *
     * Kegagalan koneksi diterjemahkan menjadi kalimat Indonesia di sini,
     * sekali. Tanpa ini, pesan yang sampai ke layar admin berbunyi "cURL error
     * 7: Failed to connect to relevansi port 8001", kalimat yang benar tetapi
     * tidak memberi tahu siapa pun bahwa container layanan model belum
     * dinyalakan.
     *
     * `ConnectionException` di Laravel juga menutupi timeout, dan itu memang
     * benar di sini: layanan yang tidak menjawab dalam batas waktu sama saja
     * tidak bisa dihubungi sejauh yang bisa dilakukan pemanggilnya.
     *
     * @param  callable(PendingRequest): Response  $panggil
     */
    private function kirim(int $timeout, callable $panggil): Response
    {
        $klien = Http::baseUrl((string) config('relevansi.base_url'))
            ->timeout($timeout)
            ->asJson();

        try {
            return $panggil($klien);
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                'Layanan model relevansi tidak bisa dihubungi. Pastikan container `relevansi` sudah berjalan.',
                previous: $e,
            );
        }
    }

    /**
     * @param  array<string, mixed>|null  $isi
     */
    private function pesan(?array $isi, string $bawaan): string
    {
        $detail = $isi['detail'] ?? null;

        return \is_string($detail) && $detail !== '' ? $detail : $bawaan;
    }
}
