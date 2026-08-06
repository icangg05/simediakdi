<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\KlasifikasiGemini;
use App\Models\AntreanGemini;
use App\Models\Artikel;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
     * Menyisir artikel yang belum dinilai Gemini, lalu mencatatnya sekali saja.
     *
     * `insertOrIgnore` bukan `insert`. Perintah ini berjalan berulang kali dan
     * selalu menemukan kandidat yang sama selama pekerjaannya belum jalan,
     * sedangkan kunci unik pada `artikel_id` akan menolak seluruh batch kalau
     * satu barisnya saja sudah ada.
     */
    private function isi(): int
    {
        $jumlah = 0;

        foreach ([1, 2, 3] as $prioritas) {
            $baru = $this->kandidat($prioritas)
                ->whereNotIn('artikel.id', AntreanGemini::query()->select('artikel_id'))
                ->pluck('artikel.id')
                ->map(fn (int $id): array => [
                    'artikel_id' => $id,
                    'prioritas' => $prioritas,
                    'status' => 'menunggu',
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all();

            foreach (array_chunk($baru, 500) as $bagian) {
                AntreanGemini::insertOrIgnore($bagian);
            }

            $jumlah += \count($baru);
        }

        return $jumlah;
    }

    /**
     * Tiga sumber pekerjaan, sesuai urutan prioritas yang diminta.
     *
     * `perlu_review` dikecualikan di ketiganya. Di situlah artikel yang
     * penilainya menolak memutuskan, dan yang ditunggu memang keputusan manusia
     * lewat tombol Relevan atau Tidak, bukan percobaan kedua dari mesin yang
     * sama.
     *
     * Salinan juga dikecualikan lewat `asli()`. Ia sudah menunjuk induknya, dan
     * menilai keduanya berarti membayar Gemini dua kali untuk berita yang sama.
     */
    private function kandidat(int $prioritas): Builder
    {
        $kueri = Artikel::withoutGlobalScopes()->asli()
            ->where('status_proses', '<>', 'perlu_review')
            ->whereNotNull('isi')
            ->where('isi', '<>', '');

        // Belum pernah dinilai siapa pun. Satu-satunya kelompok yang benar-benar
        // meninggalkan lubang di dashboard selama belum dikerjakan.
        if ($prioritas === 1) {
            return $kueri->doesntHave('analisisSentimen');
        }

        $relevan = $prioritas === 2;

        return $kueri->whereHas('analisisSentimen', function (Builder $analisis) use ($relevan) {
            $analisis->where('relevan', $relevan)
                // Provider selain gemini berarti keputusannya datang dari
                // pipeline IndoBERT lama yang sudah dihapus. Kolomnya kosong
                // pada baris paling tua karena pipeline itu memang tidak pernah
                // mengisinya.
                ->where(fn (Builder $q) => $q->whereNull('provider')->orWhere('provider', '<>', 'gemini'));

            // Prioritas 2 khusus yang labelnya sudah ada. Relevan tanpa label
            // berarti sentimennya belum pernah tuntas, dan itu keadaan yang
            // berbeda dari sekadar dinilai model lama.
            if ($relevan) {
                $analisis->whereNotNull('label_efektif');
            }
        });
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
        $this->bersihkanMacet();

        // Dibandingkan dengan null, bukan dipakai sebagai nilai kebenaran.
        // `--batas=0` adalah permintaan yang sah, yaitu isi antreannya saja
        // tanpa melepas apa pun, dan nol yang dibaca sebagai "tidak diisi"
        // justru melepas sejumlah bawaan.
        $batas = $this->option('batas') !== null
            ? (int) $this->option('batas')
            : (int) config('ai.antrean.gantung');

        $menggantung = AntreanGemini::query()
            ->whereNotNull('dijadwalkan_at')
            ->whereIn('status', ['menunggu', 'berjalan'])
            ->count();

        $ruang = $batas - $menggantung;

        if ($ruang <= 0) {
            return 0;
        }

        // Tanpa jarak, worker menghabiskan dua puluh pekerjaan dalam setengah
        // menit dan menembak Gemini berjubel. Ini bukan dugaan: pada uji jalan
        // pertama, 109 permintaan terkirim untuk 19 artikel yang berhasil,
        // artinya sekitar tujuh puluh di antaranya dijawab 429. Google tetap
        // menghitung permintaan yang ditolaknya, jadi menembak berjubel bukan
        // sekadar sia-sia, ia memakan kuota yang seharusnya dipakai artikel
        // lain.
        $baris = AntreanGemini::query()->siapDiambil()->limit($ruang)->get();
        $jeda = AntreanGemini::jedaDetik();

        foreach ($baris->values() as $urutan => $satu) {
            $mulai = now()->addSeconds((int) ($urutan * $jeda));

            // Ditandai lebih dulu, baru dilepas. Urutan sebaliknya membuat job
            // yang langsung diambil worker menemukan barisnya belum bertanda,
            // dan tarikan berikutnya melepasnya sekali lagi.
            $satu->update(['status' => 'menunggu', 'dijadwalkan_at' => $mulai]);

            KlasifikasiGemini::dispatch($satu->id)->delay($mulai);
        }

        return $baris->count();
    }


    /**
     * Mengembalikan pekerjaan yang mati tanpa sempat berpamitan.
     *
     * Job punya penangan `failed()` yang menandai barisnya sendiri, tetapi
     * penangan itu tidak ikut jalan kalau worker-nya dimatikan paksa atau
     * kontainernya dijatuhkan di tengah pekerjaan. Barisnya tertinggal
     * berstatus berjalan selamanya, ikut terhitung sebagai pekerjaan
     * menggantung, dan pada akhirnya seluruh antrean berhenti bergerak karena
     * kuotanya habis dipakai pekerjaan hantu.
     *
     * Ambangnya jauh di atas timeout job yang 300 detik, jadi pekerjaan yang
     * benar-benar masih berjalan tidak akan pernah tersapu.
     */
    private function bersihkanMacet(): void
    {
        AntreanGemini::query()
            ->where('status', 'berjalan')
            ->where('dimulai_at', '<', now()->subMinutes(30))
            ->update([
                'status' => 'gagal',
                'percobaan' => DB::raw('percobaan + 1'),
                'galat' => 'Pekerjaan berhenti di tengah jalan, kemungkinan worker dimatikan.',
                'selesai_at' => now(),
            ]);
    }
}
