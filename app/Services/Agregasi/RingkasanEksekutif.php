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
     * Jumlah titik yang dihasilkan aturan ini pada tiga pintasan rentang:
     *
     * | Rentang  | Satuan   | Titik |
     * |----------|----------|-------|
     * | 7 hari   | harian   | 7     |
     * | 30 hari  | mingguan | 5     |
     * | 90 hari  | mingguan | 13    |
     *
     * Sasarannya lima sampai lima belas titik. Di bawah lima, garis berhenti
     * punya bentuk dan hanya menyisakan dua tiga patahan. Di atas lima belas,
     * label tanggalnya mulai bertindihan dan gerigi harian kembali mendominasi.
     * Ketiga pintasan jatuh di dalam rentang itu, jadi tidak ada satuan baru
     * yang perlu ditambahkan untuk grafik ini.
     *
     * Bulanan tetap menjadi bentuk terakhir untuk rentang di atas 120 hari.
     */
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
     * Satuan untuk grafik batang beranimasi, satu tingkat lebih kasar.
     *
     * Grafik garis dan grafik batang tidak boleh memakai angka yang sama, dan
     * alasannya bukan estetika. Pada grafik garis, satu titik memakan beberapa
     * piksel dan tiga belas titik dibaca sekali pandang. Pada grafik batang,
     * satu periode memakan satu bingkai animasi penuh: dengan jeda 1,8 detik,
     * tiga belas periode berarti dua puluh tiga detik untuk satu putaran, dan
     * tidak ada yang menunggu selama itu untuk melihat periode terakhir.
     * Tujuh bingkai selesai dalam tiga belas detik.
     *
     * Sasarannya lima sampai delapan bingkai. Rentang 90 hari yang di grafik
     * garis menjadi tiga belas titik mingguan, di sini menjadi tujuh bingkai
     * dua pekanan.
     */
    public function satuanMedia(CarbonImmutable $dari, CarbonImmutable $sampai): string
    {
        $hari = $dari->diffInDays($sampai) + 1;

        return match (true) {
            $hari <= 14 => 'harian',
            $hari <= 45 => 'mingguan',
            $hari <= 120 => 'dua_mingguan',
            default => 'bulanan',
        };
    }

    /**
     * Ekspresi SQL yang memampatkan tanggal menjadi satu titik grafik.
     *
     * Aman diinterpolasi. Satuannya selalu berasal dari `satuan()` atau
     * `satuanMedia()`, tidak pernah dari request, dan tanggal awalnya dicetak
     * ulang sebagai Y-m-d oleh Carbon.
     * date_trunc dan date_bin sama-sama tidak menerima satuannya sebagai
     * parameter terikat.
     *
     * Dua pekanan memakai `date_bin`, bukan `date_trunc`, karena Postgres tidak
     * mengenal satuan dua pekan. Titik awalnya tanggal awal rentang, jadi titik
     * pertama grafik selalu jatuh tepat di tanggal yang diminta pengguna, bukan
     * di suatu Senin acak sebelum rentangnya.
     */
    private function pemampat(string $satuan, CarbonImmutable $dari): string
    {
        if ($satuan === 'dua_mingguan') {
            return "date_bin('14 days', tanggal::timestamp, timestamp '{$dari->toDateString()}')";
        }

        $trunc = ['harian' => 'day', 'mingguan' => 'week', 'bulanan' => 'month'][$satuan];

        return "date_trunc('{$trunc}', tanggal)";
    }

    /**
     * Deret untuk grafik tren, dikelompokkan menurut panjang rentangnya.
     *
     * @return array{satuan: string, baris: list<array<string, mixed>>}
     */
    public function deret(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $satuan = $this->satuan($dari, $sampai);
        $pemampat = $this->pemampat($satuan, $dari);

        $baris = DB::table('ringkasan_harian')
            ->whereNull('media_id')
            ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->groupByRaw($pemampat)
            ->orderByRaw($pemampat)
            ->get([
                // Dipotong ke ::date supaya yang sampai ke peramban tetap
                // "2026-08-07", bukan timestamp berzona yang bisa mundur
                // sehari saat diurai new Date() di sisi klien.
                DB::raw("{$pemampat}::date AS tanggal"),
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
     * Nada per media, dipecah lagi menurut periode.
     *
     * Sumbu grafiknya nama media dan harus tetap sama sepanjang animasi, jadi
     * daftar medianya dihitung sekali dari seluruh rentang, bukan per periode.
     * Media yang tidak menerbitkan apa pun pada satu periode tetap berdiri di
     * sumbunya dengan batang nol. Sumbu yang isinya berganti tiap periode
     * membuat pembaca kehilangan jejak media yang sedang diikutinya, dan itu
     * satu-satunya hal yang bisa dilakukan dengan grafik ini.
     *
     * Dibatasi dua belas media teramai. Di atas itu namanya saling tindih pada
     * lebar kartu, dan ekor daftarnya berisi media yang menerbitkan satu dua
     * berita sepanjang rentang.
     *
     * @return array{satuan: string, media: list<string>, baris: list<array<string, mixed>>}
     */
    public function deretMedia(CarbonImmutable $dari, CarbonImmutable $sampai, int $batas = 12): array
    {
        $satuan = $this->satuanMedia($dari, $sampai);
        $pemampat = $this->pemampat($satuan, $dari);

        $baris = DB::table('ringkasan_harian')
            ->join('media', 'media.id', '=', 'ringkasan_harian.media_id')
            ->whereNotNull('media_id')
            ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])
            ->groupByRaw("{$pemampat}, media.nama")
            ->orderByRaw($pemampat)
            ->get([
                DB::raw("{$pemampat}::date AS tanggal"),
                DB::raw('media.nama AS media'),
                DB::raw('sum(jumlah_negatif)::int AS negatif'),
                DB::raw('sum(jumlah_netral)::int AS netral'),
                DB::raw('sum(jumlah_positif)::int AS positif'),
            ]);

        $media = $baris
            ->groupBy('media')
            ->map(fn ($grup) => $grup->sum(fn ($b) => $b->positif + $b->netral + $b->negatif))
            // Media tanpa satu pun berita berlabel sepanjang rentang tidak
            // ditampilkan. Batang nol di dua belas periode berturut-turut hanya
            // memakan lebar yang dibutuhkan media yang benar-benar terbit.
            ->filter()
            ->sortDesc()
            ->take($batas)
            ->keys()
            ->all();

        $nol = array_fill(0, count($media), 0);

        $periode = $baris
            ->groupBy('tanggal')
            ->map(function ($grup, $tanggal) use ($media, $nol) {
                $nilai = ['positif' => $nol, 'netral' => $nol, 'negatif' => $nol];

                foreach ($grup as $b) {
                    $kolom = array_search($b->media, $media, true);

                    if ($kolom === false) {
                        continue;
                    }

                    $nilai['positif'][$kolom] = $b->positif;
                    $nilai['netral'][$kolom] = $b->netral;
                    $nilai['negatif'][$kolom] = $b->negatif;
                }

                return ['tanggal' => $tanggal, ...$nilai];
            })
            ->values()
            ->all();

        return ['satuan' => $satuan, 'media' => $media, 'baris' => $periode];
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
