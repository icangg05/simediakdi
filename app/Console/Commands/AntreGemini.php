<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\KlasifikasiGemini;
use App\Models\AntreanGemini;
use App\Models\Artikel;
use App\Models\PengaturanAi;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Mengisi antrean klasifikasi Gemini, lalu melepas sebagian ke worker.
 *
 * Dua pekerjaan dalam satu perintah karena keduanya membaca aturan prioritas
 * yang sama. Memisahkannya menjadi dua kelas berarti aturan itu ditulis dua
 * kali, dan suatu hari yang satu berubah sementara yang lain tidak.
 *
 * Dijadwalkan dua kali dengan irama berbeda. Pengisian menyisir seluruh tabel
 * artikel, jadi cukup sejam sekali. Pelepasan hanya membaca tabel antrean yang
 * sudah terindeks, jadi boleh tiap menit supaya worker tidak pernah menganggur.
 */
class AntreGemini extends Command
{
    protected $signature = 'gemini:antre
        {--isi : Sisir tabel artikel dan tambahkan kandidat baru ke antrean}
        {--batas= : Jumlah maksimal pekerjaan yang boleh menggantung sekaligus}';

    protected $description = 'Mengisi antrean klasifikasi Gemini dan melepasnya ke worker';

    public function handle(): int
    {
        if ($this->option('isi')) {
            $this->info('Kandidat baru masuk antrean: '.$this->isi());
        }

        $this->info('Pekerjaan dilepas ke worker: '.$this->lepas());

        return self::SUCCESS;
    }

    /**
     * Menyisir artikel yang belum pernah dinilai, lalu mencatatnya sekali saja.
     *
     * `insertOrIgnore` bukan `insert`. Perintah ini berjalan berulang kali dan
     * selalu menemukan kandidat yang sama selama pekerjaannya belum jalan,
     * sedangkan kunci unik pada `artikel_id` akan menolak seluruh batch kalau
     * satu barisnya saja sudah ada.
     */
    private function isi(): int
    {
        $baru = $this->kandidat()
            ->whereNotIn('artikel.id', AntreanGemini::query()->select('artikel_id'))
            ->pluck('artikel.id')
            ->map(fn (int $id): array => [
                'artikel_id' => $id,
                'prioritas' => 1,
                'status' => 'menunggu',
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        foreach (array_chunk($baru, 500) as $bagian) {
            AntreanGemini::insertOrIgnore($bagian);
        }

        return \count($baru);
    }

    /**
     * `perlu_review` dikecualikan. Di situlah artikel yang
     * penilainya menolak memutuskan, dan yang ditunggu memang keputusan manusia
     * lewat tombol Relevan atau Tidak, bukan percobaan kedua dari mesin yang
     * sama.
     */
    private function kandidat(): Builder
    {
        return Artikel::withoutGlobalScopes()
            ->where('status_proses', '<>', 'perlu_review')
            ->whereNotNull('isi')
            ->where('isi', '<>', '')
            ->doesntHave('analisisSentimen');
    }

    /**
     * Melepas pekerjaan secukupnya, bukan sebanyak-banyaknya.
     *
     * Job menunda dirinya sendiri saat kuota habis, jadi melepas tiga ribu
     * sekaligus tidak merusak apa pun. Yang rusak adalah kejelasannya: tiga ribu
     * pekerjaan yang saling menunda membuat Redis penuh dengan pekerjaan tidur,
     * dan halaman pemantauan tidak bisa lagi membedakan yang sedang dikerjakan
     * dari yang sekadar sudah dilempar.
     */
    private function lepas(): int
    {
        $this->sinkronkanHasilManual();
        $this->bersihkanMacet();

        // Dibandingkan dengan null, bukan dipakai sebagai nilai kebenaran.
        // `--batas=0` adalah permintaan yang sah, yaitu isi antreannya saja
        // tanpa melepas apa pun, dan nol yang dibaca sebagai "tidak diisi"
        // justru melepas sejumlah bawaan.
        $batas = $this->option('batas') !== null
            ? (int) $this->option('batas')
            : (int) config('ai.antrean.gantung');

        $menggantung = AntreanGemini::query()
            ->belumTuntas()
            ->whereNotNull('dijadwalkan_at')
            ->whereIn('status', ['menunggu', 'berjalan'])
            ->count();

        $ruang = $batas - $menggantung;

        if ($ruang <= 0) {
            return 0;
        }

        // Seluruh ruang dilepas langsung, seperti beberapa klik manual yang
        // berurutan. Setiap job memeriksa `terakhir_dipakai_at` secara atomik,
        // jadi worker paralel memilih kunci lain atau menunda sebesar sisa jeda
        // 15 detik; tidak ada lagi jadwal tetap satu menit per artikel.
        $baris = AntreanGemini::query()->siapDiambil()->limit($ruang)->get();
        $jedaCpu = PengaturanAi::aktif()->penyedia_relevansi === 'indobert'
            ? AntreanGemini::jedaDetik()
            : 0;
        $awal = now();

        foreach ($baris->values() as $urutan => $satu) {
            // IndoBERT tetap berjarak untuk menjaga CPU layanan lokal. Jalur
            // Gemini bernilai nol di sini karena pemilih kuncinya sendiri yang
            // mengatur kapan tiap permintaan boleh lewat.
            $mulai = $awal->copy()->addSeconds((int) ($urutan * $jedaCpu));

            // Ditandai lebih dulu, baru dilepas. Urutan sebaliknya membuat job
            // yang langsung diambil worker menemukan barisnya belum bertanda,
            // dan tarikan berikutnya melepasnya sekali lagi.
            $satu->update([
                'status' => 'menunggu',
                'dijadwalkan_at' => $mulai,
                'coba_lagi_at' => null,
            ]);

            KlasifikasiGemini::dispatch($satu->id)->delay($mulai);
        }

        return $baris->count();
    }

    /** Menutup baris aktif bila artikelnya sudah diputus lewat jalur lain. */
    private function sinkronkanHasilManual(): void
    {
        AntreanGemini::query()
            ->where('prioritas', 1)
            ->where('status', '<>', 'selesai')
            ->sudahTuntas()
            ->eachById(function (AntreanGemini $baris): void {
                $baris->update([
                    'status' => 'selesai',
                    'galat' => null,
                    'dijadwalkan_at' => null,
                    'coba_lagi_at' => null,
                    'selesai_at' => $baris->selesai_at ?? now(),
                ]);
            });
    }

    /**
     * Mengembalikan pekerjaan yang mati tanpa sempat berpamitan.
     *
     * Job punya penangan `failed()` yang menandai barisnya sendiri, tetapi
     * penangan itu tidak ikut jalan kalau worker-nya dimatikan paksa atau
     * kontainernya dijatuhkan di tengah pekerjaan. Barisnya tertinggal
     * berstatus berjalan selamanya, ikut terhitung sebagai pekerjaan
     * menggantung, dan pada akhirnya seluruh antrean berhenti bergerak karena
     * jatah gantungnya habis dipakai pekerjaan hantu.
     *
     * Ambangnya jauh di atas timeout job yang 300 detik, jadi pekerjaan yang
     * benar-benar masih berjalan tidak akan pernah tersapu.
     */
    private function bersihkanMacet(): void
    {
        AntreanGemini::query()
            ->belumTuntas()
            ->where('status', 'berjalan')
            ->where('dimulai_at', '<', now()->subMinutes(30))
            ->eachById(function (AntreanGemini $baris): void {
                $percobaan = $baris->percobaan + 1;

                $baris->update([
                    'status' => 'gagal',
                    'percobaan' => $percobaan,
                    'galat' => 'Pekerjaan berhenti di tengah jalan, kemungkinan worker dimatikan.',
                    'dijadwalkan_at' => null,
                    'coba_lagi_at' => now()->addSeconds(AntreanGemini::jedaCobaUlangDetik($percobaan)),
                    'selesai_at' => now(),
                ]);
            });

        $this->bebaskanHantu();
    }

    /**
     * Melepas kunci mati: baris menunggu yang jobnya sudah tidak ada.
     *
     * Ini kebuntuan yang benar-benar terjadi, dan mendiamkan antrean sejak
     * 2026-08-07 sampai 2026-08-08. Dua puluh baris berstatus menunggu dengan
     * `dijadwalkan_at` terisi tertinggal setelah jobnya lenyap dari tabel
     * `jobs`, entah karena `retryUntil` terlampaui atau kontainer worker
     * dijatuhkan saat pekerjaannya masih tertunda. Ketiganya berkonspirasi:
     * `lepas()` menghitungnya sebagai pekerjaan menggantung sehingga jatahnya
     * habis dan ruangnya nol, `siapDiambil()` mengecualikannya karena mengira
     * ia masih hidup, dan penyapu di atas hanya menyentuh yang berjalan.
     * Hasilnya 3.353 artikel siap kerja yang tidak akan pernah dilepas, tanpa
     * satu pun pesan galat yang menjelaskan sebabnya.
     *
     * Yang dikembalikan hanya `dijadwalkan_at`, bukan statusnya. Barisnya
     * memang tidak pernah gagal, ia cuma kehilangan jobnya, jadi jatah
     * percobaannya tidak boleh ikut terpakai.
     *
     * Ambangnya tiga puluh menit, sementara job paling lama menunda dirinya
     * lima menit. Enam kali lipat jarak itu yang menahan baris yang benar-benar
     * masih tertunda ikut terbebaskan lalu dinilai dua kali. Kalaupun kebetulan
     * itu terjadi, akibatnya satu artikel dinilai ulang, jauh lebih ringan
     * daripada seluruh antrean berhenti selamanya.
     */
    private function bebaskanHantu(): void
    {
        $jumlah = AntreanGemini::query()
            ->belumTuntas()
            ->where('status', 'menunggu')
            ->whereNotNull('dijadwalkan_at')
            ->where('dijadwalkan_at', '<', now()->subMinutes(30))
            ->update(['dijadwalkan_at' => null]);

        if ($jumlah > 0) {
            $this->warn("Pekerjaan tanpa job dibebaskan: {$jumlah}");
        }
    }
}
