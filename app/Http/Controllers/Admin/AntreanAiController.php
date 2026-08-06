<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AntreanGemini;
use App\Models\KunciGemini;
use App\Services\Ai\RotasiKunciGemini;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Antrean AI, `/admin/antrean-ai`.
 *
 * Halaman pemantauan, bukan halaman kendali. Yang mengerjakan antrean adalah
 * penjadwal dan worker, dan satu-satunya tombol di sini menyuruh penjadwal
 * menyisir lebih cepat daripada jadwal sejamnya.
 *
 * Seluruh angkanya dihitung ulang tiap kali halaman ditarik, dan halamannya
 * menarik dirinya sendiri tiap beberapa detik. Tidak ada nilai yang disimpan
 * sebagai ringkasan, karena ringkasan yang basi pada halaman pemantauan lebih
 * buruk daripada tidak ada halaman sama sekali.
 */
class AntreanAiController extends Controller
{
    public function index(RotasiKunciGemini $rotasi): Response
    {
        return Inertia::render('admin/AntreanAi', [
            'ringkasan' => $this->ringkasan(),
            'prioritas' => $this->prioritas(),
            'laju' => $this->laju(),
            'kuota' => $this->kuota($rotasi),
            'terbaru' => $this->terbaru(),
            'diperbarui' => now()->toIso8601String(),
        ]);
    }

    /** @return array<string, int> */
    private function ringkasan(): array
    {
        $jumlah = AntreanGemini::query()
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        // Gagal yang masih punya jatah percobaan bukan pekerjaan yang batal, ia
        // hanya belum berhasil, dan tarikan berikutnya akan melepasnya lagi
        // dengan sendirinya. Karena itu ia dihitung sebagai menunggu. Kalau
        // tidak, halaman ini berteriak merah untuk gangguan koneksi yang sudah
        // terjadwal ulang semenit kemudian.
        $menyerah = AntreanGemini::query()
            ->where('status', 'gagal')
            ->where('percobaan', '>=', AntreanGemini::MAKS_PERCOBAAN)
            ->count();

        return [
            'menunggu' => (int) ($jumlah['menunggu'] ?? 0) + (int) ($jumlah['gagal'] ?? 0) - $menyerah,
            'berjalan' => (int) ($jumlah['berjalan'] ?? 0),
            'selesai' => (int) ($jumlah['selesai'] ?? 0),
            'menyerah' => $menyerah,
            'total' => (int) $jumlah->sum(),
        ];
    }

    /**
     * Sisa pekerjaan per prioritas, dalam urutan pengerjaannya.
     *
     * @return list<array{nilai: int, label: string, jumlah: int}>
     */
    private function prioritas(): array
    {
        $jumlah = AntreanGemini::query()
            ->whereIn('status', ['menunggu', 'berjalan', 'gagal'])
            ->where('percobaan', '<', AntreanGemini::MAKS_PERCOBAAN)
            ->selectRaw('prioritas, count(*) as n')
            ->groupBy('prioritas')
            ->pluck('n', 'prioritas');

        return collect(AntreanGemini::PRIORITAS)
            ->map(fn (string $label, int $nilai): array => [
                'nilai' => $nilai,
                'label' => $label,
                'jumlah' => (int) ($jumlah[$nilai] ?? 0),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, int> */
    private function laju(): array
    {
        return [
            'jam' => AntreanGemini::where('status', 'selesai')
                ->where('selesai_at', '>=', now()->subHour())->count(),
            'hari' => AntreanGemini::where('status', 'selesai')
                ->where('selesai_at', '>=', now()->subDay())->count(),
        ];
    }

    /**
     * Sisa kuota beserta perkiraan kapan antrean habis.
     *
     * Perkiraannya sengaja kasar dan dihitung dari kapasitas harian, bukan dari
     * laju sejam terakhir. Laju sejam terakhir bernilai nol setiap kali kuota
     * habis, dan perkiraan yang berbunyi "tidak akan pernah selesai" setiap
     * sore bukan angka yang menolong siapa pun.
     *
     * @return array<string, int|float|null>
     */
    private function kuota(RotasiKunciGemini $rotasi): array
    {
        $kapasitas = (int) config('ai.batas_kunci.rpd')
            * KunciGemini::where('aktif', true)->count();

        $tersisa = AntreanGemini::query()
            ->whereIn('status', ['menunggu', 'berjalan', 'gagal'])
            ->where('percobaan', '<', AntreanGemini::MAKS_PERCOBAAN)
            ->count();

        // Dua pagar, dan yang paling sempit yang menentukan. Kuota harian
        // membatasi berapa banyak permintaan boleh terkirim seharian, jeda
        // antar artikel membatasi berapa cepat mereka boleh menyusul satu sama
        // lain. Menghitung dari kuota saja pernah membuat layar menjanjikan
        // enam hari untuk antrean yang jedanya menuntut dua kali lipat itu.
        $perHariKuota = $kapasitas / AntreanGemini::PERMINTAAN_PER_ARTIKEL;
        $perHariJeda = 86400 / AntreanGemini::jedaDetik();
        $perHari = min($perHariKuota, $perHariJeda);

        return [
            'sisa_hari_ini' => $rotasi->sisaHarian(),
            'kapasitas_harian' => $kapasitas,
            'tersisa' => $tersisa,
            'jeda_detik' => (int) AntreanGemini::jedaDetik(),
            'per_hari' => (int) floor($perHari),
            'perkiraan_hari' => $perHari > 0 ? ceil($tersisa / $perHari) : null,
        ];
    }

    /**
     * Pekerjaan terakhir yang bergerak, terbaru dulu.
     *
     * Yang ditampilkan hasilnya, bukan cuma judulnya. Halaman ini dibuka justru
     * saat admin curiga antreannya menilai dengan cara yang salah, dan judul
     * tanpa hasil tidak menjawab kecurigaan itu.
     *
     * @return list<array<string, mixed>>
     */
    private function terbaru(): array
    {
        return AntreanGemini::query()
            ->with(['artikel:id,judul,media_id,status_proses', 'artikel.media:id,nama', 'artikel.analisisSentimen'])
            ->whereIn('status', ['berjalan', 'selesai', 'gagal'])
            ->orderByRaw('coalesce(selesai_at, dimulai_at) desc nulls last')
            ->limit(15)
            ->get()
            ->map(function (AntreanGemini $baris): array {
                $analisis = $baris->artikel?->analisisSentimen->first();

                return [
                    'id' => $baris->id,
                    'artikel_id' => $baris->artikel_id,
                    'judul' => $baris->artikel?->judul ?? 'Artikel sudah dihapus',
                    'media' => $baris->artikel?->media?->nama,
                    'prioritas' => $baris->prioritas,
                    'status' => $baris->status,
                    'percobaan' => $baris->percobaan,
                    'galat' => $baris->galat,
                    'waktu' => ($baris->selesai_at ?? $baris->dimulai_at)?->toIso8601String(),
                    'nada' => match (true) {
                        $analisis === null => null,
                        $baris->artikel?->status_proses === 'perlu_review' => 'perlu_review',
                        (bool) $analisis->relevan => 'relevan',
                        default => 'tidak_relevan',
                    },
                    'sentimen' => $analisis?->relevan ? $analisis->label_efektif?->value : null,
                ];
            })
            ->all();
    }
}
