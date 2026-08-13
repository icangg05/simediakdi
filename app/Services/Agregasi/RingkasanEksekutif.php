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
     * Ambang harian diturunkan dari 31 hari ke 14. Pemkot dan media daerah
     * bergerak dalam siklus pekanan: kegiatan seremonial menumpuk di hari
     * kerja, redaksi berhenti di akhir pekan, dan satu kunjungan kerja bisa
     * mengangkat satu hari ke angka tiga puluh lalu menjatuhkannya ke tiga
     * keesokan harinya. Pada rentang tiga puluh hari, garis harian menggambar
     * jadwal kerja redaksi, bukan perubahan nada pemberitaan, dan pembacanya
     * melihat gerigi yang tidak menyimpulkan apa pun.
     *
     * Rentang tiga puluh hari sekarang jadi sekitar lima titik mingguan. Itu
     * sedikit, tapi lima titik yang berarti mengalahkan tiga puluh titik yang
     * tidak. Angka hariannya tidak hilang, hanya tidak lagi menjadi bentuk
     * bawaan grafiknya.
     *
     * Batas mingguan ikut naik ke 120 hari supaya rentang empat bulan tidak
     * langsung jatuh ke empat titik bulanan.
     *
     * @var array<string, string> satuan => argumen date_trunc Postgres
     */
    private const SATUAN = ['harian' => 'day', 'mingguan' => 'week', 'bulanan' => 'month'];

    public function satuan(CarbonImmutable $dari, CarbonImmutable $sampai): string
    {
        $hari = $dari->diffInDays($sampai) + 1;

        return match (true) {
            $hari <= 14 => 'harian',
            $hari <= 120 => 'mingguan',
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

    /**
     * Media yang memuat minimal satu berita relevan berlabel pada periode itu.
     *
     * Dihitung dari ketiga kolom label, bukan dari `jumlah_artikel`. Kolom itu
     * memuat seluruh hasil crawl termasuk yang tidak relevan dan yang belum
     * diklasifikasi, sedangkan angka utama panel ini berita berlabel. Bedanya
     * terlihat di layar: enam berita hari ini pernah ditemani "16 media aktif",
     * dan sepuluh di antaranya tidak menyumbang satu pun berita yang dihitung.
     */
    private function mediaAktif(CarbonImmutable $dari, CarbonImmutable $sampai): int
    {
        return DB::query()->fromSub(
            DB::table('ringkasan_harian')
                ->select('media_id')
                ->whereNotNull('media_id')
                ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
                ->groupBy('media_id')
                ->havingRaw('sum(jumlah_negatif + jumlah_netral + jumlah_positif) > 0'),
            'media_berlabel',
        )->count();
    }

    /**
     * Peringkat media pada satu periode.
     *
     * Kuerinya berangkat dari tabel `media`, bukan dari `ringkasan_harian`,
     * supaya media yang tidak memuat satu berita pun tetap bisa disebut. Rentang
     * tanggalnya ikut ke dalam klausa ON, bukan WHERE, karena WHERE akan
     * membuang kembali baris media yang tidak punya pasangan ringkasan dan
     * mengubah left join menjadi inner join tanpa terlihat.
     *
     * @param  int|null  $batas  Null berarti seluruh media, tanpa pemotongan.
     * @param  bool  $termasukTanpaBerita  Sertakan media yang nol berita pada rentang ini.
     * @return list<array<string, mixed>>
     */
    public function peringkatMedia(
        CarbonImmutable $dari,
        CarbonImmutable $sampai,
        ?int $batas = 10,
        bool $termasukTanpaBerita = false,
    ): array {
        $jumlahBerlabel = 'sum(r.jumlah_negatif + r.jumlah_netral + r.jumlah_positif)';

        $kueri = DB::table('media as m')
            ->leftJoin('ringkasan_harian as r', fn ($sambung) => $sambung
                ->on('r.media_id', '=', 'm.id')
                ->whereBetween('r.tanggal', [$dari->toDateString(), $sampai->toDateString()]))
            // Media yang sudah dihapus tidak pernah ikut. Penghapusannya lunak,
            // jadi barisnya masih ada di tabel dan `DB::table` tidak mengenal
            // global scope milik model.
            ->whereNull('m.deleted_at')
            ->groupBy('m.id', 'm.nama', 'm.tier')
            ->orderByRaw("coalesce({$jumlahBerlabel}, 0) DESC")
            // Media yang sama sama nol diurutkan menurut nama, bukan menurut
            // urutan yang dikembalikan Postgres begitu saja. Tanpa ini daftar
            // ekornya bisa berpindah pindah tiap kali halaman dimuat ulang.
            ->orderBy('m.nama');

        // Populasi yang sama dengan KPI: berita relevan yang sudah berlabel.
        // Memakai `jumlah_artikel` membuat bar media digambar dari seluruh hasil
        // crawl, sedangkan porsi negatif di dalam bar yang sama hanya dari
        // berita berlabel, sehingga media yang banyak memuat berita tidak
        // relevan terlihat paling tenang.
        if (! $termasukTanpaBerita) {
            $kueri->havingRaw("{$jumlahBerlabel} > 0");
        }

        if ($batas !== null) {
            $kueri->limit($batas);
        }

        return $kueri
            ->get([
                'm.id', 'm.nama', 'm.tier',
                DB::raw("coalesce({$jumlahBerlabel}, 0)::int AS jumlah_artikel"),
                DB::raw('coalesce(sum(r.jumlah_negatif), 0)::int AS jumlah_negatif'),
                DB::raw('coalesce(sum(r.jumlah_netral), 0)::int AS jumlah_netral'),
                DB::raw('coalesce(sum(r.jumlah_positif), 0)::int AS jumlah_positif'),
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
