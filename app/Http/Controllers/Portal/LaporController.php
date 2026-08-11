<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanLaporanBeritaRequest;
use App\Models\Artikel;
use App\Models\Media;
use App\Services\Crawler\ItemFeed;
use App\Services\Crawler\PencatatArtikel;
use App\Services\Portal\PemeriksaUrlLaporan;
use App\Support\Waktu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tambah berita, satu-satunya alur tulis di portal media.
 *
 * Tidak ada antrean persetujuan. Media partner tahu berita mana yang mereka
 * terbitkan sendiri, dan domain URL-nya sudah dipastikan milik mereka sebelum
 * disimpan, jadi berita yang dikirim langsung masuk arsip lewat jalur crawler
 * yang sama dengan berita otomatis.
 *
 * Urutan halamannya adalah alur kerjanya: berita yang sudah tampil di sistem
 * lebih dulu, lalu form untuk yang terlewat, lalu daftar kiriman sendiri
 * beserta tahap pemrosesannya. Media hanya perlu mengetik untuk berita yang
 * tidak ada di daftar pertama.
 */
class LaporController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('portal/Lapor', [
            'sudahOtomatis' => $this->sudahTampil(),
            'kiriman' => $this->kirimanSaya(),
        ]);
    }

    /**
     * Pratinjau per URL sebelum apa pun disimpan (F-49).
     *
     * Hasilnya dikembalikan lewat session, bukan JSON, supaya halamannya tetap
     * satu request Inertia dan tombol kembali peramban tidak menyisakan
     * pratinjau basi.
     */
    public function periksa(Request $request, PemeriksaUrlLaporan $pemeriksa): RedirectResponse
    {
        $data = $request->validate(['tautan' => ['required', 'string', 'max:10000']]);
        $media = $this->media($request);

        $daftar = collect(preg_split('/\R/', $data['tautan']))
            ->map(fn (string $b) => trim($b))
            ->filter()
            ->unique()
            // Batas yang sama dengan aturan penyimpanan. Menampilkan pratinjau
            // untuk 200 tautan lalu menolaknya saat kirim adalah pemborosan
            // waktu media dan kuota unduhan situsnya.
            ->take(50);

        $hasil = $daftar
            ->map(fn (string $url) => $pemeriksa->periksa($url, $media))
            ->values()
            ->all();

        return back()->with('hasilPeriksa', ['baris' => $hasil]);
    }

    public function store(
        SimpanLaporanBeritaRequest $request,
        PemeriksaUrlLaporan $pemeriksa,
        PencatatArtikel $pencatat,
    ): RedirectResponse {
        $media = $this->media($request);

        $tersimpan = 0;
        $dilewati = 0;
        $ditolak = [];

        foreach ($request->validated('baris') as $baris) {
            $periksa = $pemeriksa->periksa($baris['url'], $media);

            // Diperiksa ulang di sini, bukan dipercaya dari pratinjau. Yang
            // dikirim peramban bisa disunting, dan F-50 adalah batas antar
            // media: tanpa pemeriksaan ini satu akun media bisa menyuntikkan
            // berita milik pesaingnya ke arsip.
            if ($periksa['status'] === PemeriksaUrlLaporan::DOMAIN_SALAH) {
                $ditolak[] = $periksa['pesan'];

                continue;
            }

            if ($periksa['status'] === PemeriksaUrlLaporan::SUDAH_TERCATAT) {
                $dilewati++;

                continue;
            }

            // PencatatArtikel juga yang menjadwalkan pengambilan isinya, jadi
            // berita ini melewati ekstraksi, penilaian relevansi, dan sentimen
            // yang persis sama dengan berita temuan crawler.
            $artikel = $pencatat->catat(new ItemFeed(
                judul: $baris['judul'],
                url: $periksa['url_kanonik'],
                dipublikasikanAt: Waktu::awalHari($baris['tanggal']),
            ));

            // null berarti URL-nya sudah tercatat di sela pemeriksaan dan
            // penyimpanan, misalnya karena tombol kirim ditekan dua kali.
            if ($artikel === null) {
                $dilewati++;

                continue;
            }

            $artikel->update(['dilaporkan_oleh' => $request->user()->id]);
            $tersimpan++;
        }

        return to_route('portal.lapor')->with(
            $tersimpan > 0 ? 'sukses' : 'galat',
            $this->ringkasan($tersimpan, $dilewati, $ditolak),
        );
    }

    /**
     * Media pemilik kiriman, selalu dari akun yang login.
     *
     * Superadmin boleh membuka portal untuk melihat tampilannya, tetapi tidak
     * punya media dan karena itu tidak bisa menambah berita atas nama siapa pun.
     */
    private function media(Request $request): Media
    {
        $media = $request->user()->media;

        abort_if($media === null, 403, 'Akun ini tidak terhubung ke media mana pun, jadi tidak bisa menambah berita.');

        return $media;
    }

    /** @param  list<string>  $ditolak */
    private function ringkasan(int $tersimpan, int $dilewati, array $ditolak): string
    {
        $bagian = [];

        if ($tersimpan > 0) {
            $bagian[] = "{$tersimpan} berita ditambahkan dan sedang diproses sistem.";
        }

        if ($dilewati > 0) {
            $bagian[] = "{$dilewati} tautan dilewati karena sudah ada di sistem.";
        }

        if ($ditolak !== []) {
            $bagian[] = count($ditolak).' tautan ditolak: '.$ditolak[0];
        }

        return $bagian === [] ? 'Tidak ada yang tersimpan.' : implode(' ', $bagian);
    }

    /**
     * F-48: berita media ini yang sudah tampil di sistem.
     *
     * Populasi yang sama dengan halaman "Berita saya", yaitu yang sudah dinilai
     * relevan dan berlabel. Kalau daftar ini memakai saringan yang berbeda,
     * media akan melihat beritanya di sini tetapi tidak di sana dan menyimpulkan
     * salah satunya rusak.
     *
     * Ditampilkan sebelum form, bukan sesudah. Fungsinya ganda: mengurangi
     * kiriman ganda, dan menunjukkan bahwa sistem bekerja untuk media alih-alih
     * menambah beban mereka.
     *
     * @return list<array<string, mixed>>
     */
    private function sudahTampil(): array
    {
        return Artikel::query()
            ->relevanBerlabel()
            ->where('diambil_at', '>=', Waktu::awalHariIni()->subDays(30))
            ->latest('diambil_at')
            ->limit(50)
            ->get(['id', 'judul', 'url', 'diambil_at', 'dipublikasikan_at', 'dilaporkan_oleh'])
            ->map(fn (Artikel $a) => [
                ...$a->only(['id', 'judul', 'url']),
                'tanggal' => Waktu::tanggalWita($a->dipublikasikan_at ?? $a->diambil_at),
                // Daftar ini bercampur: sebagian ditemukan crawler, sebagian
                // ditambahkan media sendiri. Tanpa penanda, media membaca
                // seluruh baris sebagai bukti crawler bekerja, padahal sebagian
                // justru masuk karena mereka mengetiknya sendiri.
                'ditambahkan_sendiri' => $a->dilaporkan_oleh !== null,
            ])
            ->all();
    }

    /**
     * Berita yang ditambahkan media ini sendiri, beserta tahap pemrosesannya.
     *
     * Perlu ada karena berita kiriman tidak langsung tampil di "Berita saya":
     * ia harus diunduh, dinilai relevansinya, lalu dilabeli. Tanpa daftar ini
     * media mengirim berita, tidak melihatnya muncul, dan mengirimkannya lagi.
     *
     * Statusnya menyebut relevansi, bukan sentimen. Keduanya berbeda dan hanya
     * sentimen yang dirahasiakan dari media (dokumen 01 bagian 8). Relevansi
     * justru harus terbaca, kalau tidak media tidak punya cara mengetahui
     * mengapa beritanya tidak muncul.
     *
     * @return list<array<string, mixed>>
     */
    private function kirimanSaya(): array
    {
        return Artikel::query()
            ->whereNotNull('dilaporkan_oleh')
            ->with('analisisSentimen:id,artikel_id,relevan,label_efektif')
            ->latest('diambil_at')
            ->limit(100)
            // `status_proses` ikut dipilih karena tahapPortal() membacanya untuk
            // membedakan halaman yang gagal diunduh dari yang masih mengantre.
            ->get(['id', 'judul', 'url', 'diambil_at', 'dipublikasikan_at', 'status_proses'])
            ->map(fn (Artikel $a) => [
                ...$a->only(['id', 'judul', 'url']),
                'tanggal' => Waktu::tanggalWita($a->dipublikasikan_at ?? $a->diambil_at),
                // Tahapnya dihitung Artikel::tahapPortal(), bukan di sini.
                // Pemeriksaan URL di halaman yang sama menjawab pertanyaan yang
                // persis sama, dan dua salinan aturan berarti satu layar bisa
                // menyebut "sedang diproses" untuk berita yang di layar
                // sebelahnya sudah berstatus di luar pantauan.
                'status' => $a->tahapPortal(),
            ])
            ->all();
    }
}
