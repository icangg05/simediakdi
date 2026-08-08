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
     * Satuan waktu grafik menurut panjang rentang yang dipilih.
     *
     * Rentang tiga bulan yang digambar per hari menghasilkan sembilan puluh
     * titik yang tidak terbaca. Satuannya ditentukan di sini, bukan dikirim
     * pengguna, jadi tidak ada parameter baru yang perlu divalidasi dan tidak
     * ada kombinasi ganjil seperti rentang tujuh hari yang diminta per bulan.
     *
     * @var array<string, string> satuan => argumen date_trunc Postgres
     */
    private const SATUAN = ['harian' => 'day', 'mingguan' => 'week', 'bulanan' => 'month'];

    public function satuan(CarbonImmutable $dari, CarbonImmutable $sampai): string
    {
        $hari = $dari->diffInDays($sampai) + 1;

        return match (true) {
            $hari <= 31 => 'harian',
            $hari <= 92 => 'mingguan',
            default => 'bulanan',
        };
    }

    /**
     * Deret untuk grafik tren, dikelompokkan menurut panjang rentangnya.
     *
     * @return array{satuan: string, baris: list<array<string, mixed>>}
     */
    public function deret(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $satuan = $this->satuan($dari, $sampai);

        // Aman diinterpolasi: nilainya selalu salah satu dari konstanta di
        // atas, tidak pernah berasal dari request. date_trunc tidak menerima
        // satuannya sebagai parameter terikat.
        $trunc = self::SATUAN[$satuan];

        $baris = DB::table('ringkasan_harian')
            ->whereNull('media_id')
            ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->groupByRaw("date_trunc('{$trunc}', tanggal)")
            ->orderByRaw("date_trunc('{$trunc}', tanggal)")
            ->get([
                // Dipotong ke ::date supaya yang sampai ke peramban tetap
                // "2026-08-07", bukan timestamp berzona yang bisa mundur
                // sehari saat diurai new Date() di sisi klien.
                DB::raw("date_trunc('{$trunc}', tanggal)::date AS tanggal"),
                DB::raw('sum(jumlah_artikel)::int AS jumlah_artikel'),
                DB::raw('sum(jumlah_negatif)::int AS jumlah_negatif'),
                DB::raw('sum(jumlah_netral)::int AS jumlah_netral'),
                DB::raw('sum(jumlah_positif)::int AS jumlah_positif'),
                DB::raw('sum(jumlah_perlu_review)::int AS jumlah_perlu_review'),
            ])
            ->map(fn ($b) => (array) $b)
            ->all();

        return ['satuan' => $satuan, 'baris' => $baris];
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

        $totalBerlabel = $sekarang['berlabel'];

        return [
            // Angka utama panel eksekutif: artikel yang lolos relevansi dan
            // sudah punya label. Bukan `artikel`, yang menghitung seluruh hasil
            // crawl termasuk yang tidak relevan dan yang belum diklasifikasi.
            'berlabel' => $totalBerlabel,
            'berlabel_selisih' => $totalBerlabel - $sebelumnya['berlabel'],
            // Penyebutnya tetap dikirim, dan dipakai menampilkan cakupan
            // analisis. Menyembunyikan berapa banyak yang belum diklasifikasi
            // membuat panel terlihat lebih lengkap daripada keadaannya.
            'artikel' => $sekarang['artikel'],
            'cakupan_persen' => $sekarang['artikel'] === 0 ? 0.0 : round($totalBerlabel / $sekarang['artikel'] * 100, 1),
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

        $total = array_map('intval', (array) $baris);

        // Ketiga label ini hanya terisi untuk artikel yang relevan (lihat JOIN
        // di RingkasanHarian), dan satu artikel hanya punya satu baris analisis
        // karena uq_analisis_artikel. Jumlahnya sama persis dengan banyaknya
        // artikel relevan yang sudah berlabel, jadi tidak perlu kolom baru.
        $total['berlabel'] = $total['negatif'] + $total['netral'] + $total['positif'];

        return $total;
    }

    /** Media yang memuat minimal satu artikel pada periode itu. */
    private function mediaAktif(CarbonImmutable $dari, CarbonImmutable $sampai): int
    {
        return DB::query()->fromSub(
            DB::table('ringkasan_harian')
                ->select('media_id')
                ->whereNotNull('media_id')
                ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
                ->groupBy('media_id')
                ->havingRaw('sum(jumlah_negatif + jumlah_netral + jumlah_positif) > 0'),
            'media_relevan',
        )->count();
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
            ->havingRaw('sum(r.jumlah_negatif + r.jumlah_netral + r.jumlah_positif) > 0')
            ->orderByRaw('sum(r.jumlah_negatif + r.jumlah_netral + r.jumlah_positif) DESC')
            ->limit($batas)
            ->get([
                'm.id', 'm.nama', 'm.tier',
                DB::raw('sum(r.jumlah_negatif + r.jumlah_netral + r.jumlah_positif)::int AS jumlah_artikel'),
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
