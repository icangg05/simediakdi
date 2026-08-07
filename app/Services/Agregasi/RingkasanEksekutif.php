<?php

namespace App\Services\Agregasi;

use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Angka untuk dashboard eksekutif dan halaman turunannya.
 *
 * Seluruhnya dibaca dari `ringkasan_harian`, tabel praperhitungan yang ditulis
 * ulang scheduler tiap sepuluh menit. Menghitung agregasi saat request berarti
 * membuang alasan tabel itu ada, dan dashboard harus selesai render di bawah
 * dua detik pada koneksi 4G.
 */
class RingkasanEksekutif
{
    /**
     * Deret harian untuk grafik tren.
     *
     * @return list<array<string, mixed>>
     */
    public function deretHarian(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        return DB::table('ringkasan_harian')
            ->whereNull('media_id')
            ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->orderBy('tanggal')
            ->get([
                'tanggal', 'jumlah_artikel',
                'jumlah_negatif', 'jumlah_netral', 'jumlah_positif', 'jumlah_perlu_review',
            ])
            ->map(fn ($b) => (array) $b)
            ->all();
    }

    /**
     * Total satu periode, beserta pembandingnya pada periode sebelumnya yang
     * sama panjang.
     *
     * Angka disajikan bersama pembandingnya, "48 berita, naik 12 dari minggu
     * lalu" mengalahkan "48 berita" (dokumen 04 bagian A.7).
     *
     * @return array<string, mixed>
     */
    public function kpi(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $panjang = $dari->diffInDays($sampai) + 1;
        $sekarang = $this->total($dari, $sampai);
        $sebelumnya = $this->total($dari->subDays($panjang), $dari->subDay());

        $totalBerlabel = $sekarang['negatif'] + $sekarang['netral'] + $sekarang['positif'];

        return [
            'artikel' => $sekarang['artikel'],
            'artikel_selisih' => $sekarang['artikel'] - $sebelumnya['artikel'],
            'negatif' => $sekarang['negatif'],
            'negatif_selisih' => $sekarang['negatif'] - $sebelumnya['negatif'],
            // Proporsi dihitung terhadap artikel yang punya label, bukan seluruh
            // artikel: yang belum dianalisis tidak boleh mengecilkan persentase
            // dan membuat keadaan terlihat lebih tenang daripada sebenarnya.
            'negatif_persen' => $totalBerlabel === 0 ? 0.0 : round($sekarang['negatif'] / $totalBerlabel * 100, 1),
            'positif' => $sekarang['positif'],
            'positif_selisih' => $sekarang['positif'] - $sebelumnya['positif'],
            'netral' => $sekarang['netral'],
            'perlu_review' => $sekarang['perlu_review'],
            'media_aktif' => $this->mediaAktif($dari, $sampai),
        ];
    }

    /** @return array<string, int> */
    private function total(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $baris = DB::table('ringkasan_harian')
            ->whereNull('media_id')
            ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->selectRaw('
                coalesce(sum(jumlah_artikel), 0) AS artikel,
                coalesce(sum(jumlah_negatif), 0) AS negatif,
                coalesce(sum(jumlah_netral), 0) AS netral,
                coalesce(sum(jumlah_positif), 0) AS positif,
                coalesce(sum(jumlah_perlu_review), 0) AS perlu_review
            ')
            ->first();

        return array_map('intval', (array) $baris);
    }

    /** Media yang memuat minimal satu artikel pada periode itu. */
    private function mediaAktif(CarbonImmutable $dari, CarbonImmutable $sampai): int
    {
        return DB::table('ringkasan_harian')
            ->whereNotNull('media_id')
            ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->where('jumlah_artikel', '>', 0)
            ->distinct()
            ->count('media_id');
    }

    /**
     * Peringkat media pada satu periode.
     *
     * @return list<array<string, mixed>>
     */
    public function peringkatMedia(CarbonImmutable $dari, CarbonImmutable $sampai, int $batas = 10): array
    {
        return DB::table('ringkasan_harian as r')
            ->join('media as m', 'm.id', '=', 'r.media_id')
            ->whereNotNull('r.media_id')
            ->whereBetween('r.tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->groupBy('m.id', 'm.nama', 'm.tier')
            ->havingRaw('sum(r.jumlah_artikel) > 0')
            ->orderByRaw('sum(r.jumlah_artikel) DESC')
            ->limit($batas)
            ->get([
                'm.id', 'm.nama', 'm.tier',
                DB::raw('sum(r.jumlah_artikel)::int AS jumlah_artikel'),
                DB::raw('sum(r.jumlah_negatif)::int AS jumlah_negatif'),
                DB::raw('sum(r.jumlah_netral)::int AS jumlah_netral'),
                DB::raw('sum(r.jumlah_positif)::int AS jumlah_positif'),
            ])
            ->map(fn ($b) => (array) $b)
            ->all();
    }

    /** Rentang bawaan: tujuh hari terakhir menurut kalender Kendari. */
    public function rentangBawaan(): array
    {
        $sampai = CarbonImmutable::parse(Waktu::tanggalWita(now()));

        return [$sampai->subDays(6), $sampai];
    }
}
