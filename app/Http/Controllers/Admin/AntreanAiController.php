<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AntreanGemini;
use App\Models\KunciGemini;
use App\Services\Ai\RotasiKunciGemini;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Antrean AI, `/admin/antrean-ai`.
 *
 * Halaman pemantauan murni, tanpa satu pun tombol. Yang mengerjakan antrean
 * adalah penjadwal dan worker, dan artikel yang selesai diekstrak sudah
 * mengantre sendiri lewat PenyelesaiArtikel, jadi tidak ada lagi yang perlu
 * disuruh dari layar ini.
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
        $ringkasan = $this->ringkasan();

        return Inertia::render('admin/AntreanAi', [
            'ringkasan' => $ringkasan,
            'aktivitas' => $this->aktivitas($ringkasan, $rotasi),
            'prioritas' => $this->prioritas(),
            'laju' => $this->laju(),
            'kuota' => $this->kuota($rotasi),
            'terbaru' => $this->terbaru(),
            'diperbarui' => now()->toIso8601String(),
        ]);
    }

    /**
     * Keadaan mesinnya, bukan keadaan halamannya.
     *
     * Menggantikan dua tombol yang dulu berdiri di sini. Yang satu hanya
     * menjeda tarikan halaman dan tidak pernah menyentuh antrean, yang satu
     * lagi menunjuk ke method yang tidak pernah ada dan selalu menjawab 500.
     * Keduanya menjawab pertanyaan yang salah. Yang benar-benar dicari admin
     * saat membuka halaman ini cuma satu: mesinnya masih hidup atau tidak.
     *
     * @param  array<string, int>  $ringkasan
     * @return array<string, mixed>
     */
    private function aktivitas(array $ringkasan, RotasiKunciGemini $rotasi): array
    {
        // `whereNotNull` wajib. Postgres menaruh NULL paling depan pada urutan
        // menurun, jadi tanpa saringan ini yang terambil justru pekerjaan yang
        // belum pernah selesai, dan penunjuknya akan berbunyi macet selamanya.
        //
        // value() lewat model, bukan max(), supaya cast datetime ikut jalan.
        $terakhir = AntreanGemini::query()
            ->whereNotNull('selesai_at')
            ->latest('selesai_at')
            ->value('selesai_at');

        $sisa = $ringkasan['menunggu'] + $ringkasan['berjalan'];

        // Kuota habis berarti tidak ada satu pun kunci yang boleh dipanggil
        // sekarang. Bukan tebakan dari `dijadwalkan_at` pekerjaan yang sedang
        // tidur, yang dulu dipakai di sini dan salah dengan dua cara sekaligus.
        //
        // Ia berbunyi habis untuk pekerjaan yang sekadar menunggu gilirannya
        // dalam irama normal, dan ia terus berbunyi habis lama setelah admin
        // menambahkan kunci baru yang jatahnya masih utuh, karena pekerjaan
        // yang telanjur tidur tidak mengubah `dijadwalkan_at`-nya sendiri.
        // Layar menyatakan kuota habis sementara tombol Klasifikasi di halaman
        // sebelah bekerja normal dengan kunci yang sama.
        $pulih = $rotasi->kuotaPulihAt();

        // Pekerjaan yang paling dekat gilirannya, kalau ia memang sudah dilepas
        // dan sedang tidur di Redis. Dipakai untuk membedakan antrean yang mati
        // dari antrean yang sedang menunggu giliran, bukan untuk menyimpulkan
        // soal kuota.
        $lanjut = AntreanGemini::query()
            ->whereIn('status', ['menunggu', 'berjalan'])
            ->whereNotNull('dijadwalkan_at')
            ->orderBy('dijadwalkan_at')
            ->value('dijadwalkan_at');

        // Dua kali jeda antar artikel, bukan satu kali. Satu kali terlalu
        // ketat: artikel yang butuh dua panggilan Gemini wajar melewatinya
        // sedikit, dan penunjuk yang berkedip merah tiap beberapa menit akan
        // diabaikan orang persis pada saat ia benar-benar merah.
        $ambang = AntreanGemini::jedaDetik() * 2;
        $diam = $terakhir?->diffInSeconds(now(), absolute: true);

        return [
            'keadaan' => match (true) {
                // Tertangkap tepat saat Gemini sedang menjawab. Jendelanya
                // sempit, beberapa detik dari tiap satu menit, jadi keadaan ini
                // jarang terlihat walaupun antreannya sehat. Karena itu ia
                // bukan satu-satunya tanda hidup, hanya yang paling tegas.
                $ringkasan['berjalan'] > 0 => 'bekerja',
                $sisa === 0 => 'kosong',
                $diam !== null && $diam <= $ambang => 'menunggu',
                // Diperiksa sebelum macet, bukan sesudah. Antrean yang tidur
                // menunggu kuota memang tidak menyelesaikan apa pun berjam-jam,
                // dan itu bukan kerusakan.
                $pulih !== null => 'tertunda',
                // Kuotanya masih ada dan pekerjaannya sudah dilepas, hanya
                // belum tiba gilirannya. Menyebutnya macet mengirim admin
                // memeriksa worker yang sebenarnya sehat.
                $lanjut?->isFuture() => 'menunggu',
                default => 'macet',
            },
            'terakhir_selesai_at' => $terakhir?->toIso8601String(),
            'dilanjutkan_at' => $pulih?->toIso8601String(),
        ];
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
            // Pemakaian, bukan sisa. Sisa menuntut batas yang benar, dan batas
            // yang benar hanya diketahui untuk kunci yang pernah kehabisan
            // kuota sampai Google menyebut angkanya.
            'terkirim_hari_ini' => $rotasi->terkirimHarian(),
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
