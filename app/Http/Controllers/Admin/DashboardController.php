<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AntreanGemini;
use App\Models\Artikel;
use App\Models\KunciGemini;
use App\Models\LogCrawl;
use App\Models\Media;
use App\Models\PengaturanAi;
use App\Models\SumberFeed;
use App\Support\Waktu;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('admin/Dashboard', [
            'pantauan' => config('pantauan.nama'),
            'kpi' => $this->kpi(),
            'ekstraksi' => $this->ekstraksi(),
            'kesehatan' => $this->kesehatan(),
            'mediaBermasalah' => $this->mediaBermasalah(),
            'artikelTerbaru' => $this->artikelTerbaru(),
        ]);
    }

    /**
     * Angka disajikan bersama pembandingnya, bukan berdiri sendiri.
     *
     * Hitungan beritanya disaring relevansi. Halaman ini menjawab "berapa berita
     * Pemkot yang masuk", dan artikel yang sudah dinilai tidak relevan bukan
     * jawaban atas pertanyaan itu. Penyaringan yang sama dipakai pada daftar
     * berita terbaru di bawahnya, supaya angka di atas dan baris di bawah
     * menghitung populasi yang sama.
     */
    private function kpi(): array
    {
        $mulaiPekanIni = now()->subDays(7);
        $mulaiPekanLalu = now()->subDays(14);

        $pekanIni = Artikel::query()->relevan()->where('diambil_at', '>=', $mulaiPekanIni)->count();
        $pekanLalu = Artikel::query()
            ->relevan()
            ->whereBetween('diambil_at', [$mulaiPekanLalu, $mulaiPekanIni])
            ->count();

        return [
            // Batas hari WITA, bukan UTC. whereDate('diambil_at', today())
            // akan salah setiap pukul 00.00-08.00 waktu Kendari.
            'artikel_hari_ini' => Artikel::query()
                ->relevan()
                ->where('diambil_at', '>=', Waktu::awalHariIni())
                ->count(),
            'artikel_pekan_ini' => $pekanIni,
            'selisih_pekan_lalu' => $pekanIni - $pekanLalu,
            // Sengaja tidak disaring relevansi. Artikel yang gagal diproses tidak
            // pernah sampai ke penilaian, jadi ia tidak punya keputusan relevan
            // sama sekali. Menyaringnya akan membuat angka ini nol selamanya dan
            // menyembunyikan justru keadaan yang perlu ditindaklanjuti.
            'gagal_proses' => Artikel::query()->where('status_proses', 'gagal')->count(),
            // Media, bukan sumber feed. Yang dikelola admin dan yang tercetak di
            // seluruh layar lain adalah nama media. Satu media bisa punya
            // beberapa feed, sehingga jumlah feed tidak pernah cocok dengan
            // daftar media yang dilihat admin di halaman Media.
            'media_aktif' => Media::query()->where('aktif', true)->count(),
        ];
    }

    /**
     * Kemajuan ekstraksi isi artikel mentah, tahap paling awal setelah crawl.
     *
     * Yang dipantau di sini adalah pengunduhan halaman, bukan penilaian Gemini.
     * Keduanya berjalan pada kecepatan yang berbeda jauh, dan menggabungkannya
     * dalam satu penunjuk membuat perkiraan waktu selesai tidak berarti apa-apa:
     * ekstraksi habis dalam hitungan menit sementara penilaian butuh berhari-hari.
     *
     * Dibaca dari `artikel.status_proses`, bukan dari isi antrean Redis.
     * Alasannya: status artikel adalah kebenaran yang bertahan, sedangkan
     * antrean Redis kosong bukan berarti pekerjaannya selesai, bisa juga
     * berarti job-nya hilang.
     *
     * @return array<string, mixed>
     */
    private function ekstraksi(): array
    {
        $per = Artikel::query()
            ->selectRaw('status_proses, count(*) as n')
            ->groupBy('status_proses')
            ->pluck('n', 'status_proses');

        $mentah = (int) ($per['mentah'] ?? 0);

        // Batas hari WITA, sama seperti KPI di atasnya. Backfill pun terhitung
        // hari ini, karena `diambil_at` mencatat kapan barisnya masuk, bukan
        // kapan beritanya terbit.
        $awalHari = Waktu::awalHariIni();

        $masuk = Artikel::query()->where('diambil_at', '>=', $awalHari)->count();
        $sisa = Artikel::query()
            ->where('diambil_at', '>=', $awalHari)
            ->where('status_proses', 'mentah')
            ->count();

        $laju = $this->lajuEkstraksi();

        return [
            'mentah' => $mentah,
            'masuk_hari_ini' => $masuk,
            'diekstrak_hari_ini' => $masuk - $sisa,
            'persen' => $masuk > 0 ? round(($masuk - $sisa) / $masuk * 100, 1) : 100.0,
            'laju_per_menit' => $laju,
            // Dikirim sebagai saat, bukan sebagai durasi. Durasi yang dihitung
            // di server basi seketika di layar yang menyegarkan sendiri,
            // sedangkan saat selesai tetap benar sampai tarikan berikutnya.
            'estimasi_selesai_at' => $laju > 0 && $mentah > 0
                ? now()->addSeconds((int) ceil($mentah / $laju * 60))->toJSON()
                : null,
            // Tahap sesudahnya, ditampilkan sebagai angka pendamping supaya
            // terlihat bahwa artikel yang selesai diekstrak tidak menguap.
            'belum_klasifikasi' => (int) ($per['isi_diambil'] ?? 0),
            'antre_ai' => AntreanGemini::query()->whereIn('status', ['menunggu', 'berjalan'])->count(),
            'gagal' => (int) ($per['gagal'] ?? 0),
        ];
    }

    /**
     * Artikel yang selesai diekstrak per menit, diukur bukan ditebak.
     *
     * Kecepatannya ditentukan jeda antar permintaan dan kecepatan server media
     * yang sedang ditarik, bukan angka yang bisa dituliskan di config. Jendela
     * sepuluh menit cukup panjang untuk meredam satu situs lambat, cukup pendek
     * untuk mengikuti keadaan sekarang.
     *
     * Dibatasi pada `isi_diambil` supaya artikel yang sudah lanjut ke penilaian
     * tidak ikut terhitung dua kali. Yang keburu dinilai dalam sepuluh menit itu
     * tidak terhitung sama sekali, jadi angkanya condong ke bawah, dan perkiraan
     * yang terlalu lama jauh lebih tidak merugikan daripada yang terlalu cepat.
     */
    private function lajuEkstraksi(): float
    {
        $menit = 10;

        $selesai = Artikel::query()
            ->where('status_proses', 'isi_diambil')
            ->where('updated_at', '>=', now()->subMinutes($menit))
            ->count();

        return round($selesai / $menit, 2);
    }

    /** Titik hijau, kuning, atau merah untuk crawler dan layanan NLP. */
    private function kesehatan(): array
    {
        // Bukan max(): fungsi agregat melewati cast, hasilnya string mentah.
        $crawlTerakhir = LogCrawl::query()->latest('dimulai_at')->first()?->dimulai_at;
        $jamSejakCrawl = $crawlTerakhir ? now()->diffInHours($crawlTerakhir, absolute: true) : null;

        // Diukur dari hitungan gagal saja, bukan dari kolom `aktif`.
        //
        // Dulu syaratnya sumber yang sudah dimatikan otomatis. Penonaktifan itu
        // sudah dicabut, jadi memakai `aktif` membuat lampu ini hijau selamanya
        // sekalipun ada sumber yang gagal berpuluh kali berturut-turut. Sumber
        // yang rusak tetap perlu terlihat, apalagi sekarang ia terus mencoba.
        $sumberRusak = SumberFeed::query()->where('gagal_berturut', '>=', 5)->count();

        return [
            'crawler' => [
                'status' => match (true) {
                    $jamSejakCrawl === null => 'merah',
                    $jamSejakCrawl > 3 => 'merah',
                    $jamSejakCrawl > 1 => 'kuning',
                    default => 'hijau',
                },
                'keterangan' => $crawlTerakhir
                    ? 'Crawl terakhir '.$crawlTerakhir->diffForHumans()
                    : 'Crawler belum pernah berjalan. Periksa scheduler dan worker queue.',
            ],
            'sumber' => [
                'status' => $sumberRusak === 0 ? 'hijau' : ($sumberRusak > 3 ? 'merah' : 'kuning'),
                'keterangan' => $sumberRusak === 0
                    ? 'Semua sumber feed berjalan normal.'
                    : "{$sumberRusak} sumber gagal 5 kali berturut-turut atau lebih. Periksa URL-nya di halaman media.",
            ],
            'gemini' => $this->kesehatanGemini(),
        ];
    }

    /**
     * Kunci Gemini terpasang atau tidak, tanpa memanggil API.
     *
     * Panggilan nyata sengaja tidak dilakukan di sini. Halaman admin tidak
     * boleh ikut lambat atau ikut gagal hanya karena penyedia sedang sibuk, dan
     * satu panggilan per pembukaan dashboard memakan kuota free tier untuk
     * pertanyaan yang jawabannya sudah ada di tabel `kunci_gemini`.
     *
     * @return array{status: string, keterangan: string}
     */
    private function kesehatanGemini(): array
    {
        if (! KunciGemini::query()->exists()) {
            return [
                'status' => 'merah',
                'keterangan' => 'Belum ada kunci API Gemini. Tambahkan di halaman Pengaturan, '
                    .'klasifikasi tidak bisa dijalankan tanpa itu.',
            ];
        }

        // Kuning, bukan merah. Kuota yang habis pulih sendiri, dan menandainya
        // bermasalah membuat keadaan yang normal terbaca sama gawatnya dengan
        // sistem yang belum dikonfigurasi sama sekali.
        if (! KunciGemini::query()->tersedia()->exists()) {
            return [
                'status' => 'kuning',
                'keterangan' => 'Semua kunci API Gemini sedang kena limit. Klasifikasi jalan lagi '
                    .'setelah kuotanya pulih, waktunya terlihat di halaman Pengaturan.',
            ];
        }

        return [
            'status' => 'hijau',
            'keterangan' => 'Model '.PengaturanAi::aktif()->model
                .' terkonfigurasi. Klasifikasi dijalankan manual dari Antrean Klasifikasi.',
        ];
    }

    /**
     * Media yang penarikan beritanya sedang bermasalah.
     *
     * Kegagalan tercatat di tabel `sumber_feed`, tapi yang ditampilkan adalah
     * medianya. Nama feed hanya dikenal saat mendaftarkannya, sedangkan seluruh
     * layar lain di sistem ini menyebut media, jadi daftar bernama feed memaksa
     * admin menerjemahkan sendiri satu nama ke nama lain sebelum bisa bertindak.
     *
     * Dikelompokkan per media, bukan per feed. Satu media dengan tiga feed rusak
     * sebelumnya menghasilkan tiga baris beruntun bertuliskan nama yang sama
     * persis, dan tidak ada cara membedakannya dari layar ini. Sekarang barisnya
     * satu, dengan jumlah feed yang bermasalah sebagai keterangan.
     *
     * @return list<array<string, mixed>>
     */
    private function mediaBermasalah(): array
    {
        return SumberFeed::query()
            ->with('media:id,nama')
            ->where('gagal_berturut', '>', 0)
            ->orderByDesc('gagal_berturut')
            ->get(['id', 'media_id', 'nama', 'gagal_berturut', 'pesan_error_terakhir'])
            ->groupBy(fn (SumberFeed $feed) => $feed->media_id ?? 0)
            ->map(function (Collection $feed, int|string $mediaId) {
                // Feed terparah mewakili medianya. Ia yang paling lama gagal,
                // jadi pesan errornya yang paling mungkin menjelaskan sebabnya.
                $terparah = $feed->first();

                return [
                    'id' => (int) $mediaId,
                    'nama' => $terparah->media?->nama ?? 'Media belum ditautkan',
                    'gagal_berturut' => (int) $terparah->gagal_berturut,
                    'jumlah_sumber' => $feed->count(),
                    'pesan_error_terakhir' => $terparah->pesan_error_terakhir,
                ];
            })
            ->sortByDesc('gagal_berturut')
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * Berita relevan yang paling baru masuk.
     *
     * Disaring relevansi, sama seperti KPI di atasnya. Artikel yang sudah
     * diputus tidak relevan bukan berita Pemkot, dan menampilkannya di daftar
     * berjudul berita terbaru membuat admin memeriksa ulang sesuatu yang sudah
     * selesai diputuskan.
     *
     * Sentimennya ikut dikirim karena daftar ini sekarang hanya berisi artikel
     * relevan, jadi kolom itu selalu punya isi yang berarti. Label yang dibaca
     * adalah `label_efektif`, kolom generated yang sudah mendahulukan koreksi
     * manual admin di atas tebakan model.
     *
     * @return list<array<string, mixed>>
     */
    private function artikelTerbaru(): array
    {
        return Artikel::query()
            ->relevan()
            ->with(['media:id,nama', 'analisisSentimen:id,artikel_id,label_efektif,perlu_review'])
            ->orderByDesc('diambil_at')
            ->limit(8)
            ->get(['id', 'media_id', 'judul', 'diambil_at'])
            ->map(function (Artikel $artikel) {
                $analisis = $artikel->analisisSentimen->first();

                return [
                    'id' => $artikel->id,
                    'judul' => $artikel->judul,
                    // Nama media, bukan nama sumber feed. Feed adalah alamat
                    // teknis tempat berita ditarik, dan admin tidak pernah
                    // menyebutnya saat membicarakan asal sebuah berita.
                    'media' => $artikel->media?->nama,
                    'diambil_at' => $artikel->diambil_at,
                    'label' => $analisis?->label_efektif?->value,
                    'perlu_review' => (bool) $analisis?->perlu_review,
                ];
            })
            ->all();
    }
}
