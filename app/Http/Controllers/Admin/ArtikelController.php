<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\Media;
use App\Services\Ai\KlasifikasiArtikel;
use App\Services\PembuangArtikel;
use App\Support\Waktu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Artikel, `/admin/artikel`.
 *
 * Satu halaman untuk seluruh perjalanan sebuah berita: masuk dari crawler,
 * diklasifikasi Gemini lewat tombol, lalu dikoreksi manusia bila perlu.
 * Sebelumnya pekerjaan ini terpecah ke dua halaman, arsip artikel untuk membaca
 * dan antrean klasifikasi untuk bertindak, dan admin harus tahu sendiri harus
 * membuka yang mana.
 *
 * Klasifikasi dijalankan sinkron, satu artikel satu klik. Itu keputusan
 * sementara yang disengaja: selama prompt masih disetel, hasil yang muncul
 * seketika di layar jauh lebih cepat dinilai benar atau salah daripada hasil
 * yang muncul beberapa menit kemudian di antrean.
 */
class ArtikelController extends Controller
{
    /**
     * Tiga tahap perjalanan artikel, beserta status yang masuk ke masing-masing.
     *
     * Dipetakan di sini, bukan di Vue, supaya daftar tahap yang muncul di layar
     * tidak pernah berbeda dari daftar status yang dipahami kueri. `mentah`
     * ikut ke tahap pertama: isinya memang belum diambil, tetapi bagi admin
     * keduanya sama saja, yaitu berita yang belum dinilai.
     *
     * `tidak_relevan` masuk ke Selesai karena ia memang sudah diputuskan.
     * Memisahkannya berarti 2.279 artikel yang dibuang Gemini tidak punya
     * tempat, dan keputusan yang salah tidak bisa dikoreksi siapa pun.
     */
    private const TAHAP = [
        'selesai' => ['label' => 'Selesai', 'status' => ['selesai', 'tidak_relevan', 'dianalisis']],
        'belum' => ['label' => 'Belum diklasifikasi', 'status' => ['mentah', 'isi_diambil']],
        'review' => ['label' => 'Menunggu review', 'status' => ['perlu_review']],
    ];

    /**
     * Tahap yang terbuka lebih dulu saat halaman dibuka tanpa parameter.
     *
     * Selesai, bukan Belum diklasifikasi. Yang paling sering dicari admin
     * adalah hasil, bukan pekerjaan yang menunggu, dan tumpukan ribuan artikel
     * yang belum dinilai sebagai tampilan pembuka terasa seperti daftar tugas
     * yang tidak pernah habis.
     */
    private const TAHAP_BAWAAN = 'selesai';

    public function index(Request $request): Response
    {
        $tahap = $request->string('tahap')->toString();

        if (! \array_key_exists($tahap, self::TAHAP)) {
            $tahap = self::TAHAP_BAWAAN;
        }

        $relevansi = $this->relevansiTerpilih($request, $tahap);

        return Inertia::render('admin/artikel/Index', [
            'tahap' => $tahap,
            'daftarTahap' => $this->daftarTahap($request),
            'relevansi' => $relevansi,
            'daftarRelevansi' => $this->daftarRelevansi($request, $tahap),
            'sentimen' => $this->sentimenTerpilih($request, $relevansi),
            'media' => $request->string('media')->toString() ?: null,
            'koreksi' => $request->boolean('koreksi'),
            'pantauan' => config('pantauan.nama'),
            // Domain, bukan nama redaksi. Yang tertera di kolom Media pada
            // tabel adalah nama, jadi daftar pilihannya memakai domain supaya
            // dua media dengan nama mirip tetap bisa dibedakan sekali lihat.
            'opsiMedia' => Media::query()->orderBy('nama')->get(['id', 'nama', 'domain'])
                ->map(fn (Media $m) => [
                    'nilai' => (string) $m->id,
                    'label' => $m->domain ?: $m->nama,
                ])->all(),
            'tanggal' => [
                'dari' => $request->query('dari'),
                'sampai' => $request->query('sampai'),
            ],
            'artikel' => $this->daftar($request, $tahap),
            'pembuangan' => $this->pembuangan($request, $tahap, $relevansi),
        ]);
    }

    /**
     * Keadaan tombol buang untuk saringan yang sedang terpasang.
     *
     * Tombolnya hanya ada pada dua tampilan: Selesai yang disaring Tidak
     * relevan, dan Menunggu review. Di tampilan lain nilainya `boleh = false`
     * dan layar tidak menggambar apa pun. Ini bukan sekadar menyembunyikan
     * tombol. Endpoint-nya menyaring ulang id yang dikirim lewat
     * PembuangArtikel, jadi artikel relevan tetap tidak bisa dihapus meski
     * permintaannya disusun tangan.
     *
     * @return array{boleh: bool, jumlah: int}
     */
    private function pembuangan(Request $request, string $tahap, ?string $relevansi): array
    {
        $boleh = $tahap === 'review' || ($tahap === 'selesai' && $relevansi === 'tidak_relevan');

        return [
            'boleh' => $boleh,
            // Dihitung di atas saringan yang sedang terpasang, bukan di atas
            // seluruh tabel. "Buang semua" pada daftar yang sudah disaring satu
            // media harus berarti semua milik media itu, bukan semua yang ada.
            'jumlah' => $boleh ? $this->kueriBuang($request, $tahap)->count() : 0,
        ];
    }

    /**
     * Artikel yang boleh dibuang pada saringan yang sedang terpasang.
     *
     * Sengaja memakai ulang saringanUmum() yang sama dengan tabelnya, supaya
     * angka pada tombol dan isi yang benar-benar terhapus tidak pernah
     * berselisih. Tidak ada daftar pengecualian di atasnya. Buang semua berarti
     * seluruh baris yang terlihat, tanpa terkecuali.
     */
    private function kueriBuang(Request $request, string $tahap): Builder
    {
        $kueri = $this->saringanUmum(
            Artikel::withoutGlobalScopes()->asli()
                ->whereIn('status_proses', self::TAHAP[$tahap]['status']),
            $request,
        );

        if ($tahap === 'selesai') {
            $kueri->whereHas('analisisSentimen', fn ($a) => $a->where('relevan', false));
        }

        return $kueri;
    }

    /**
     * Membuang artikel terpilih, satu atau banyak sekaligus.
     *
     * Id yang dikirim layar diperlakukan sebagai usulan, bukan perintah.
     * PembuangArtikel menyaringnya ulang, jadi daftar yang sudah basi atau
     * disusun tangan tidak bisa menghapus artikel relevan.
     */
    public function hapus(Request $request, PembuangArtikel $pembuang): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['required', 'array', 'min:1', 'max:500'],
            'id.*' => ['integer'],
        ]);

        return back()->with('sukses', $this->ringkasBuang($pembuang->buang($data['id'], 'artikel')));
    }

    /** Membuang seluruh artikel yang cocok dengan saringan yang sedang terpasang. */
    public function hapusSemua(Request $request, PembuangArtikel $pembuang): RedirectResponse
    {
        $tahap = $request->string('tahap')->toString();

        if (! \array_key_exists($tahap, self::TAHAP)) {
            $tahap = self::TAHAP_BAWAAN;
        }

        if (! $this->pembuangan($request, $tahap, $this->relevansiTerpilih($request, $tahap))['boleh']) {
            return back()->with('galat', 'Buang semua hanya berlaku pada daftar Tidak relevan dan Menunggu review.');
        }

        return back()->with('sukses', $this->ringkasBuang(
            $pembuang->buang($this->kueriBuang($request, $tahap)->pluck('id'), 'artikel-semua'),
        ));
    }

    /** @param  array{dibuang: int, dilindungi: int}  $hasil */
    private function ringkasBuang(array $hasil): string
    {
        // Yang dilewati disebutkan, bukan disembunyikan. Admin yang memilih
        // sepuluh baris lalu melihat tujuh terhapus berhak tahu ke mana tiga
        // sisanya, tanpa harus menebak. Sekarang hanya ada satu sebab tersisa:
        // id yang dikirim bukan artikel tidak relevan atau perlu review.
        $dilewati = $hasil['dilindungi'] > 0
            ? " {$hasil['dilindungi']} dilewati karena bukan artikel tidak relevan atau menunggu review."
            : '';

        if ($hasil['dibuang'] === 0) {
            return 'Tidak ada artikel yang dibuang.'.$dilewati;
        }

        return "{$hasil['dibuang']} artikel dibuang. URL-nya dicatat supaya tidak masuk lagi lewat crawl.".$dilewati;
    }

    public function show(Artikel $artikel): Response
    {
        $artikel->load([
            'media:id,nama,domain',
            'sumberFeed:id,nama',
            'induk:id,judul',
            'salinan:id,judul,media_id,diambil_at,artikel_induk_id',
            'salinan.media:id,nama',
            'analisisSentimen.pengoreksi:id,name',
        ]);

        return Inertia::render('admin/artikel/Detail', [
            'artikel' => $artikel,
        ]);
    }

    /**
     * Menjalankan Gemini untuk satu artikel, sekarang juga.
     *
     * Galat penyedia tidak dilempar ke halaman sebagai layar 500. Rate limit
     * dan gangguan koneksi adalah hal yang wajar terjadi di free tier, dan
     * admin yang melihatnya butuh kalimat yang bisa ditindaklanjuti, bukan
     * stack trace.
     */
    public function klasifikasi(Artikel $artikel, KlasifikasiArtikel $klasifikasi): RedirectResponse
    {
        if ($artikel->isi === null || trim($artikel->isi) === '') {
            return back()->with('galat', 'Artikel ini belum punya isi, jadi tidak ada yang bisa dinilai.');
        }

        try {
            $hasil = $klasifikasi->jalankan($artikel);
        } catch (Throwable $galat) {
            report($galat);

            return back()->with('galat', 'Gemini gagal dipanggil: '.$galat->getMessage());
        }

        // Daftar hasil kosong berarti artikelnya sudah punya keputusan manusia
        // dan Gemini sengaja dilewati. Itu tidak terbaca dari hasilnya sendiri,
        // dan tanpa catatan ini toast-nya terlihat sama persis dengan
        // klasifikasi yang benar-benar berjalan.
        return back()
            ->with($this->pesan(
                $artikel,
                $hasil === [] ? 'Sudah ada keputusan manusia, Gemini tidak dipanggil' : null,
            ))
            ->with('tautan', $this->tautan($artikel));
    }

    /**
     * Keputusan relevansi dari manusia. Tidak pernah ditimpa klasifikasi ulang.
     *
     * Dikunci ke artikel, bukan ke baris analisis. Artikel yang belum pernah
     * disentuh Gemini belum punya baris analisis sama sekali, dan admin yang
     * sudah tahu jawabannya tidak perlu membayar satu panggilan hanya untuk
     * mendapatkan baris yang bisa dikoreksinya.
     */
    public function relevansi(
        Request $request,
        Artikel $artikel,
        KlasifikasiArtikel $klasifikasi,
    ): RedirectResponse {
        $relevan = $request->validate(['relevan' => ['required', 'boolean']])['relevan'];

        // Penjaga yang sama dengan tombol Klasifikasi, dan sempat tidak ada di
        // sini. Akibatnya nyata: menandai relevan artikel tanpa isi tetap
        // meneruskannya ke penilaian sentimen, Gemini menilai dari judul saja,
        // dan kutipan buktinya lolos verifikasi karena judul ikut dikirim.
        // Hasilnya label yang terlihat sah untuk berita yang tidak pernah
        // terbaca isinya.
        if ($relevan && ($artikel->isi === null || trim($artikel->isi) === '')) {
            return back()->with('galat', 'Artikel ini belum punya isi, jadi sentimennya tidak bisa dinilai.');
        }

        // `relevan_manual` adalah penandanya, `relevan` adalah nilainya. Tanpa
        // penanda terpisah, klasifikasi ulang menimpa kolom yang sama dan
        // keputusan manusia hilang tanpa jejak.
        $kolom = [
            'relevan' => $relevan,
            'relevan_manual' => $relevan,
            'relevan_dikoreksi_oleh' => $request->user()->id,
            'relevan_dikoreksi_at' => now(),
        ];

        // Keputusan manusia menghapus sisa penilaian AI yang sudah tidak
        // berlaku. Tanpa ini, artikel yang ditandai tidak relevan tetap membawa
        // `perlu_review` menyala dan alasan dari penilaian sentimen sebelumnya,
        // sehingga layar detailnya berbunyi "perlu review, label asli netral"
        // untuk artikel yang barusan diputus manusia sebagai tidak relevan.
        // Keadaan itu benar-benar terjadi pada artikel 5832.
        //
        // Daftar kolom yang dikosongkan dibaca dari konstanta, bukan ditulis
        // ulang di sini. Jalur klasifikasi AI menuliskan hal yang sama, dan
        // sempat berbeda isinya: `model_versi` tertinggal di satu jalur dan
        // tidak di jalur lain, sampai halaman detail menyebut model IndoBERT
        // untuk penilaian yang dikerjakan Gemini.
        if (! $relevan) {
            $kolom += AnalisisSentimen::SENTIMEN_KOSONG + [
                'reason_code' => 'keputusan_manusia',
                'reason_summary' => 'Ditandai tidak relevan oleh admin.',
                'evidence' => null,
            ];
        }

        AnalisisSentimen::updateOrCreate(['artikel_id' => $artikel->id], $kolom);

        if (! $relevan) {
            $artikel->update(['status_proses' => 'tidak_relevan']);

            return back()
                ->with($this->pesan($artikel, 'Ditandai oleh admin'))
                ->with('tautan', $this->tautan($artikel));
        }

        // Sentimen dijalankan setelahnya, sekali. Panggilan Gemini memakan
        // detikan, jadi tidak ditaruh di dalam transaksi apa pun.
        try {
            $klasifikasi->jalankanSentimen($artikel);
        } catch (Throwable $galat) {
            report($galat);

            return back()->with('galat', 'Ditandai relevan, tetapi sentimennya gagal dinilai: '
                .$galat->getMessage());
        }

        return back()
            ->with($this->pesan($artikel, 'Ditandai oleh admin'))
            ->with('tautan', $this->tautan($artikel));
    }

    /**
     * Mencabut seluruh koreksi manusia, lalu menilai ulang dari nol.
     *
     * Penilaian ulang bukan tambahan, melainkan keharusan yang datang dari
     * bentuk datanya. Sentimen punya dua kolom terpisah, `label_model` milik AI
     * dan `label_manual` milik admin, jadi mencabut koreksi cukup mengosongkan
     * yang kedua dan putusan AI muncul kembali dengan sendirinya lewat
     * `label_efektif`. Relevansi tidak begitu: ia hanya punya satu kolom
     * `relevan` yang ditimpa saat admin memutuskan, sehingga putusan AI yang
     * lama sudah tidak tersimpan di mana pun. Tanpa penilaian ulang artikelnya
     * akan berhenti pada nilai manusia yang justru baru saja dicabut.
     */
    public function reset(Artikel $artikel, KlasifikasiArtikel $klasifikasi): RedirectResponse
    {
        $baris = AnalisisSentimen::where('artikel_id', $artikel->id)->first();

        if ($baris === null || ($baris->relevan_manual === null && $baris->label_manual === null)) {
            return back()->with('galat', 'Artikel ini tidak punya koreksi manusia yang bisa dicabut.');
        }

        if ($artikel->isi === null || trim($artikel->isi) === '') {
            return back()->with('galat', 'Artikel ini belum punya isi, jadi tidak bisa dinilai ulang.');
        }

        $baris->update([
            'relevan_manual' => null,
            'relevan_dikoreksi_oleh' => null,
            'relevan_dikoreksi_at' => null,
            'label_manual' => null,
            'dikoreksi_oleh' => null,
            'dikoreksi_at' => null,
            'catatan_koreksi' => null,
        ]);

        try {
            $klasifikasi->jalankan($artikel);
        } catch (Throwable $galat) {
            report($galat);

            // Koreksinya sudah terlanjur dicabut, dan itu tidak dibatalkan.
            // Barisnya kini berisi hasil AI lama tanpa penanda manusia, keadaan
            // yang bisa diperbaiki dengan menekan Klasifikasi sekali lagi.
            // Mengembalikan koreksi yang sudah dicabut justru menyembunyikan
            // bahwa perintahnya sebagian sudah berjalan.
            return back()->with('galat', 'Koreksi dicabut, tetapi penilaian ulang gagal: '
                .$galat->getMessage());
        }

        return back()
            ->with($this->pesan($artikel, 'Koreksi dicabut dan dinilai ulang'))
            ->with('tautan', $this->tautan($artikel));
    }

    /**
     * Isi toast: judul sebagai kepala, hasil penilaian sebagai keterangan.
     *
     * Hasilnya dikirim sebagai data, bukan sebagai kalimat jadi. Dua alasannya.
     * Pertama, frontend perlu mewarnai kata sentimennya sendiri, dan itu tidak
     * bisa dilakukan pada satu kalimat utuh. Kedua, menebak warna dengan
     * mencari kata "relevan" di dalam teks akan menandai pesan "ditandai tidak
     * relevan" sebagai relevan, dan salahnya justru pada arah yang berlawanan.
     *
     * `catatan` untuk hal yang tidak terbaca dari hasilnya sendiri, misalnya
     * koreksi yang barusan dicabut atau Gemini yang sengaja tidak dipanggil.
     *
     * Dibaca ulang dari database, bukan dari model yang ada di memori.
     * `label_efektif` adalah kolom generated berisi COALESCE koreksi manusia dan
     * hasil model, jadi nilainya baru ada setelah barisnya kembali dari
     * database.
     *
     * @return array{sukses: string, catatan: string|null, nada: string|null, sentimen: string|null}
     */
    private function pesan(Artikel $artikel, ?string $catatan = null): array
    {
        $baris = AnalisisSentimen::where('artikel_id', $artikel->id)->first();

        return [
            // Judul saja, tanpa kalimat hasil. Hasilnya dirakit di frontend dari
            // `nada` dan `sentimen` supaya kata sentimennya bisa diberi warna
            // sendiri, dan itu tidak bisa dilakukan pada satu kalimat utuh.
            'sukses' => Str::limit($artikel->judul, 65),
            'catatan' => $catatan,
            // Perlu review diperiksa lebih dulu, dan itu bukan urutan yang bisa
            // ditukar. Artikel yang Gemini ragukan tersimpan dengan `relevan`
            // bernilai false, bukan karena ia memutuskan artikelnya tidak
            // relevan melainkan karena ia menolak memutuskan. Membaca kolom itu
            // saja membuat toast berbunyi "Tidak relevan" berikut border merah
            // untuk artikel yang justru belum diputuskan apa pun.
            'nada' => match (true) {
                $baris === null => null,
                $artikel->fresh()?->status_proses === 'perlu_review' => 'perlu_review',
                (bool) $baris->relevan => 'relevan',
                default => 'tidak_relevan',
            },
            // Sentimen hanya ikut untuk artikel relevan. Baris yang pernah
            // dinilai relevan lalu dikoreksi menjadi tidak relevan tetap
            // menyimpan label lamanya, dan label itu sudah tidak dihitung
            // agregasi mana pun.
            // `->value`, bukan enumnya sendiri. Flash ini juga dibaca sebagai
            // data session biasa, dan enum yang bocor ke sana membuat
            // perbandingan dengan string biasa gagal tanpa pesan yang jelas.
            'sentimen' => $baris?->relevan ? $baris->label_efektif?->value : null,
        ];
    }

    /**
     * Tautan ke halaman detail artikel, untuk ditempel di toast.
     *
     * Artikel yang selesai dinilai berpindah tahap dan hilang dari daftar yang
     * sedang dibuka. Menyebut judulnya saja membuat admin tahu apa yang
     * terjadi tetapi tidak punya cara membukanya lagi tanpa berpindah tab dan
     * mencarinya kembali.
     *
     * @return array{url: string, label: string}
     */
    private function tautan(Artikel $artikel): array
    {
        return ['url' => "/admin/artikel/{$artikel->id}", 'label' => 'Lihat'];
    }

    /**
     * Relevansi yang berlaku, dengan Relevan sebagai bawaan.
     *
     * Tidak ada pilihan "semua". Artikel yang sudah diklasifikasi selalu jatuh
     * ke salah satu dari dua, dan mencampurnya menghasilkan daftar yang
     * campur aduk tanpa ada pertanyaan yang benar-benar dijawabnya.
     *
     * Hanya berlaku di tahap Selesai, dan itu bukan penyederhanaan. Di Belum
     * diklasifikasi belum ada baris analisis sama sekali. Di Menunggu review
     * seluruh barisnya bernilai `relevan = false`, bukan karena Gemini
     * memutuskan artikelnya tidak relevan melainkan karena ia menolak
     * memutuskan, dan false hanyalah cara keraguan itu disimpan. Menawarkan
     * saringan Relevan di sana menghasilkan tabel kosong yang terlihat seperti
     * kerusakan, padahal justru sesuai rancangan.
     */
    private function relevansiTerpilih(Request $request, string $tahap): ?string
    {
        if ($tahap !== 'selesai') {
            return null;
        }

        return $request->string('relevansi')->toString() === 'tidak_relevan'
            ? 'tidak_relevan'
            : 'relevan';
    }

    /**
     * Sentimen yang berlaku, atau null bila tidak disaring.
     *
     * Berbeda dari relevansi, sentimen tidak punya nilai bawaan. Ketiga nadanya
     * setara dan memilih salah satu sebagai bawaan akan menyembunyikan dua per
     * tiga isi tabel tanpa admin pernah memintanya.
     *
     * Hanya berlaku pada daftar artikel relevan. Sentimen memang cuma dinilai
     * setelah relevansi berbunyi relevan, jadi menyaringnya di tempat lain
     * selalu mengosongkan tabel.
     */
    private function sentimenTerpilih(Request $request, ?string $relevansi): ?string
    {
        if ($relevansi !== 'relevan') {
            return null;
        }

        $sentimen = $request->string('sentimen')->toString();

        return \in_array($sentimen, ['positif', 'netral', 'negatif'], true) ? $sentimen : null;
    }

    /**
     * Jumlah artikel relevan dan tidak relevan pada tahap yang sedang dibuka.
     *
     * Dihitung dalam batas tahap, bukan seluruh tabel. Angka lintas tahap akan
     * selalu sama di ketiga tombol tahap dan karena itu tidak menjawab apa pun.
     *
     * Saringan media, tanggal, dan pencarian sengaja tidak ikut. Sama seperti
     * angka pada tombol tahap, ini penunjuk seberapa besar tumpukannya, bukan
     * pratinjau hasil kombinasi filter yang sedang disusun.
     *
     * @return list<array{nilai: string, label: string, jumlah: int}>
     */
    private function daftarRelevansi(Request $request, string $tahap): array
    {
        $jumlah = $this->saringanUmum(
            Artikel::withoutGlobalScopes()->asli()
                ->whereIn('status_proses', self::TAHAP[$tahap]['status']),
            $request,
        )
            ->join('analisis_sentimen', 'analisis_sentimen.artikel_id', '=', 'artikel.id')
            ->selectRaw('analisis_sentimen.relevan, count(*) as jumlah')
            ->groupBy('analisis_sentimen.relevan')
            // Postgres mengembalikan boolean, dan PHP memakainya sebagai kunci
            // array integer 1 dan 0. Ditulis apa adanya, bukan lewat `true`
            // dan `false` yang terlihat lebih rapi tetapi menyembunyikan
            // konversi yang sebenarnya terjadi.
            ->pluck('jumlah', 'relevan');

        return [
            ['nilai' => 'relevan', 'label' => 'Relevan', 'jumlah' => (int) ($jumlah[1] ?? 0)],
            ['nilai' => 'tidak_relevan', 'label' => 'Tidak relevan', 'jumlah' => (int) ($jumlah[0] ?? 0)],
        ];
    }

    /**
     * Saringan yang berlaku di seluruh kelompok tombol sekaligus di tabelnya.
     *
     * Media, tanggal, pencarian, dan saringan koreksi tidak dimiliki satu
     * kelompok tombol mana pun, jadi semuanya ikut ke setiap penghitungan.
     * Tanpa ini angka pada
     * tombol menjanjikan isi yang berbeda dari yang benar-benar muncul begitu
     * tombolnya ditekan, dan angka yang berbohong lebih buruk daripada tidak
     * ada angka sama sekali.
     */
    private function saringanUmum(Builder $kueri, Request $request): Builder
    {
        $kueri
            ->when($request->filled('media'), fn (Builder $q) => $q->where(
                'media_id', $request->integer('media'),
            ))
            ->when($request->filled('cari'), fn (Builder $q) => $q->where(
                'judul', 'ilike', '%'.$request->string('cari')->toString().'%',
            ))
            // Baris yang salah satu koreksinya terisi, bukan keduanya. Relevansi
            // dan sentimen dikoreksi lewat dua jalur terpisah, dan artikel yang
            // hanya ditandai tidak relevan tidak akan pernah punya label manual.
            // Menuntut keduanya membuat justru koreksi yang paling sering
            // dilakukan admin hilang dari daftar.
            ->when($request->boolean('koreksi'), fn (Builder $q) => $q->whereHas(
                'analisisSentimen', fn ($a) => $a
                    ->whereNotNull('relevan_manual')
                    ->orWhereNotNull('label_manual'),
            ));

        $this->saringTanggal($kueri, $request);

        return $kueri;
    }

    /** @return list<array{nilai: string, label: string, jumlah: int}> */
    private function daftarTahap(Request $request): array
    {
        $jumlah = $this->saringanUmum(
            Artikel::withoutGlobalScopes()->asli(), $request,
        )
            ->selectRaw('status_proses, count(*) as jumlah')
            ->groupBy('status_proses')
            ->pluck('jumlah', 'status_proses');

        return collect(self::TAHAP)
            ->map(fn (array $tahap, string $nilai): array => [
                'nilai' => $nilai,
                'label' => $tahap['label'],
                'jumlah' => collect($tahap['status'])->sum(fn (string $s) => (int) ($jumlah[$s] ?? 0)),
            ])
            ->values()
            ->all();
    }

    /**
     * Daftar artikel pada tahap terpilih, terbaru dulu.
     *
     * Urutannya tanggal terbit, lalu tanggal masuk. Yang dibaca admin adalah
     * kapan beritanya terbit, bukan kapan crawler kebetulan menemukannya, dan
     * keduanya bisa berselisih beberapa hari pada media yang feed-nya jarang
     * disegarkan.
     *
     * Berbeda dari saringan rentang tanggal, yang tetap membaca `diambil_at`.
     * Saringan itu menjawab pertanyaan lain, yaitu apa yang masuk pada rentang
     * tertentu, dan angkanya harus sama dengan seluruh grafik harian.
     *
     * Artikel salinan dikecualikan. Ia sudah menunjuk induknya, dan menilai
     * keduanya berarti membayar Gemini dua kali untuk berita yang sama.
     *
     * @return array<string, mixed>
     */
    private function daftar(Request $request, string $tahap): array
    {
        $relevansi = $this->relevansiTerpilih($request, $tahap);
        $sentimen = $this->sentimenTerpilih($request, $relevansi);

        $kueri = $this->saringanUmum(
            Artikel::withoutGlobalScopes()
                ->with(['media:id,nama', 'analisisSentimen'])
                ->asli()
                ->whereIn('status_proses', self::TAHAP[$tahap]['status']),
            $request,
        )
            ->when($relevansi === 'relevan', fn (Builder $q) => $q->whereHas(
                'analisisSentimen', fn ($a) => $a->where('relevan', true),
            ))
            ->when($relevansi === 'tidak_relevan', fn (Builder $q) => $q->whereHas(
                'analisisSentimen', fn ($a) => $a->where('relevan', false),
            ))
            // `label_efektif` yang dibaca, bukan `label_model`. Ia kolom
            // generated berisi COALESCE koreksi manusia dan hasil model, jadi
            // menyaring Positif ikut menemukan artikel yang dikoreksi admin
            // menjadi positif walau Gemini menyebutnya lain.
            ->when($sentimen !== null, fn (Builder $q) => $q->whereHas(
                'analisisSentimen', fn ($a) => $a->where('label_efektif', $sentimen),
            ));

        // `pageName: 'halaman'` wajib, bukan selera. Tombol paginasi di
        // DataTable mengirim parameter `halaman`, dan paginator bawaan Laravel
        // membaca `page`. Tanpa penyelarasan ini tombolnya menembak URL yang
        // benar tetapi selalu mengembalikan halaman satu.
        $artikel = $kueri
            // Tanggal terbit lebih dulu, tanggal masuk menyusul sebagai
            // pemecah seri.
            //
            // NULLS LAST, bukan bawaan Postgres. Pada DESC, Postgres menaruh
            // null paling atas, sehingga artikel dari feed yang tidak
            // mencantumkan tanggal terbit justru menguasai puncak daftar. Saat
            // ini seluruh 4.137 artikel punya tanggal terbit, jadi penanda ini
            // menjaga sesuatu yang belum pernah terjadi. Ia tetap ada karena
            // media baru bisa ditambahkan kapan saja, dan feed yang tanggalnya
            // kosong tidak boleh menggeser seluruh daftar sekali ia masuk.
            ->orderByRaw('artikel.dipublikasikan_at DESC NULLS LAST')
            // Crawler menarik satu feed sekaligus, jadi artikel yang terbit
            // pada menit yang sama bisa punya urutan yang tidak jelas tanpa
            // pemecah ini.
            ->latest('diambil_at')
            // Pemecah seri terakhir, dan bukan hiasan. Dua kolom di atas masih
            // bisa sama persis pada artikel yang ditarik dari satu feed yang
            // sama. Urutan yang tidak pasti membuat paginasi mengulang baris
            // di halaman berikutnya dan melewatkan baris lain tanpa jejak.
            ->orderByDesc('artikel.id')
            ->paginate(25, pageName: 'halaman')
            ->withQueryString();

        $artikel->through(fn (Artikel $satu): array => [
            'id' => $satu->id,
            'judul' => $satu->judul,
            'url' => $satu->url,
            'media' => $satu->media?->nama,
            // Bisa null: tidak semua feed mencantumkan tanggal terbit.
            'dipublikasikan_at' => $satu->dipublikasikan_at,
            'diambil_at' => $satu->diambil_at,
            'status_proses' => $satu->status_proses,
            'analisis' => $this->analisis($satu),
        ]);

        return $artikel->toArray();
    }

    /** @return array<string, mixed>|null */
    private function analisis(Artikel $artikel): ?array
    {
        $analisis = $artikel->analisisSentimen->first();

        if ($analisis === null) {
            return null;
        }

        return [
            'id' => $analisis->id,
            'relevan' => $analisis->relevan,
            'relevan_manual' => $analisis->relevan_manual,
            'label_efektif' => $analisis->label_efektif,
            'label_manual' => $analisis->label_manual,
            'perlu_review' => $analisis->perlu_review,
            'provider' => $analisis->provider,
            'reason_summary' => $analisis->reason_summary,
            'evidence' => $analisis->evidence,
            'dianalisis_at' => $analisis->dianalisis_at,
        ];
    }

    /**
     * Rentang tanggal dibaca pada `diambil_at`, bukan `dipublikasikan_at`.
     * Tanggal dari feed bisa null atau salah, sedangkan waktu pengambilan
     * selalu terisi, dan itu yang dipakai seluruh grafik harian.
     */
    private function saringTanggal(Builder $kueri, Request $request): void
    {
        // Admin memilih tanggal menurut kalender Kendari, bukan UTC.
        if ($dari = $request->query('dari')) {
            $kueri->where('diambil_at', '>=', Waktu::awalHari($dari));
        }

        if ($sampai = $request->query('sampai')) {
            $kueri->where('diambil_at', '<=', Waktu::akhirHari($sampai));
        }
    }
}
