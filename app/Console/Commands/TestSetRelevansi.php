<?php

namespace App\Console\Commands;

use App\Enums\StatusLabelRelevansi;
use App\Models\SampelRelevansi;
use Illuminate\Console\Command;

/**
 * Menumbuhkan test set evaluasi dengan sampel acak. Dokumen 10 bagian 9.6.
 *
 * Test set v1 berisi 52 sampel dan dibekukan sejak 4 Agustus 2026. Sesudah itu
 * seluruh pelabelan datang dari antrean "Model ragu", yaitu artikel tersulit di
 * korpus, dan karena test terkunci semuanya jatuh ke train dan validation.
 * Akibatnya terukur: presisi 96,2% di test lawan 79,4% di validation, dengan
 * hard negative 7,7% lawan 14,0%. Dua ujian yang tidak setara.
 *
 * Perintah ini mengambil sampel **acak**, dan kata itu yang paling penting.
 * Test yang diisi artikel sulit akan terlalu pesimistis, test yang membeku
 * bulan lalu terlalu optimistis. Keputusan promosi ke produksi menuntut cermin
 * sebaran nyata, bukan salah satu dari keduanya.
 *
 * Dua tahap, mengikuti pola relevance:konsistensi:
 *
 *   relevance:test-set siapkan   lalu labeli lewat antrean evaluasi acak
 *   relevance:test-set kunci     setelah selesai dilabeli
 *
 * Jeda itu perlu. Sampel harus punya label manusia lebih dulu, karena hanya
 * sampel berlabel yang boleh masuk snapshot.
 */
class TestSetRelevansi extends Command
{
    protected $signature = 'relevance:test-set
        {tahap : siapkan atau kunci}
        {--jumlah=150 : Banyak sampel acak yang ditandai}
        {--seed=42 : Benih pengacakan, supaya bisa diulang}';

    protected $description = 'Menandai dan mengunci sampel acak sebagai anggota test set evaluasi';

    public function handle(): int
    {
        return match ($this->argument('tahap')) {
            'siapkan' => $this->siapkan(),
            'kunci' => $this->kunci(),
            default => $this->gagalTahap(),
        };
    }

    /**
     * Menandai sampel acak yang belum dilabeli sebagai calon anggota test.
     *
     * Diambil dari yang belum dilabeli, bukan dari yang sudah. Sampel yang sudah
     * dilabeli seluruhnya berasal dari antrean prioritas, jadi memilih dari sana
     * berarti mewarisi bias yang justru sedang diperbaiki.
     */
    private function siapkan(): int
    {
        $jumlah = (int) $this->option('jumlah');

        $sampel = SampelRelevansi::belumDilabeli()
            ->whereRaw("COALESCE(metadata_sumber->>'evaluasi_acak', '') = ''")
            ->inRandomOrder((string) $this->option('seed'))
            ->limit($jumlah)
            ->get(['id', 'metadata_sumber']);

        if ($sampel->count() < $jumlah) {
            $this->warn("Hanya {$sampel->count()} sampel tersedia, diminta {$jumlah}.");
        }

        foreach ($sampel as $satu) {
            $satu->update([
                'metadata_sumber' => array_merge($satu->metadata_sumber ?? [], [
                    'evaluasi_acak' => [
                        'disiapkan_at' => now()->toIso8601String(),
                        'seed' => (int) $this->option('seed'),
                    ],
                ]),
            ]);
        }

        $this->info("{$sampel->count()} sampel acak ditandai sebagai calon test set.");
        $this->line('Labeli lewat /admin/model-relevansi?tab=dataset&evaluasi=1');
        $this->line('Setelah semuanya berlabel, jalankan: php artisan relevance:test-set kunci');

        return self::SUCCESS;
    }

    /**
     * Mengunci calon yang sudah berlabel sebagai anggota test permanen.
     *
     * `terkunci_test` adalah penanda yang dibaca pembagi snapshot, jadi sampel
     * ini akan selalu jatuh ke test di snapshot berikutnya dan tidak pernah ikut
     * melatih. Yang belum berlabel dilewati, bukan diikutkan: sampel tanpa label
     * tidak bisa masuk snapshot sama sekali, dan menguncinya hanya membuat
     * hitungan test terlihat lebih besar daripada isinya.
     */
    private function kunci(): int
    {
        $calon = SampelRelevansi::whereRaw("metadata_sumber->'evaluasi_acak' IS NOT NULL")
            ->where('is_excluded', false);

        $belumBerlabel = (clone $calon)->whereNull('label_manual')->count();

        $siap = (clone $calon)
            ->whereNotNull('label_manual')
            ->where('status_label', '!=', StatusLabelRelevansi::TerkunciTest->value)
            ->get(['id', 'label_manual']);

        if ($siap->isEmpty()) {
            $this->warn('Tidak ada calon berlabel yang perlu dikunci.');

            return self::SUCCESS;
        }

        SampelRelevansi::whereIn('id', $siap->pluck('id'))
            ->update(['status_label' => StatusLabelRelevansi::TerkunciTest->value]);

        $relevan = $siap->where('label_manual.value', 'relevan')->count();

        $this->info("{$siap->count()} sampel dikunci sebagai test set ({$relevan} relevan, ".($siap->count() - $relevan).' tidak relevan).');

        if ($belumBerlabel > 0) {
            $this->warn("{$belumBerlabel} calon masih belum berlabel dan dilewati. Jalankan lagi setelah selesai.");
        }

        $total = SampelRelevansi::where('status_label', StatusLabelRelevansi::TerkunciTest->value)->count();

        $this->line("Total anggota test set sekarang: {$total}.");
        $this->line('Buat snapshot baru lalu latih ulang untuk mengukurnya.');

        return self::SUCCESS;
    }

    private function gagalTahap(): int
    {
        $this->error('Tahap harus siapkan atau kunci.');

        return self::FAILURE;
    }
}
