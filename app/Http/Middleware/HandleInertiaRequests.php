<?php

namespace App\Http\Middleware;

use App\Models\KunciGemini;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
            ],
            // `nonce` ikut dikirim supaya dua pesan yang isinya sama persis
            // tetap terbaca sebagai dua kejadian. Tanpa itu, mengklasifikasi
            // dua artikel berturut-turut yang hasilnya sama membuat toast kedua
            // tidak pernah muncul, dan admin mengira klik keduanya tidak jalan.
            'flash' => [
                'sukses' => fn () => $request->session()->get('sukses'),
                'galat' => fn () => $request->session()->get('galat'),
                // Tautan opsional yang menempel pada pesan, dirender sebagai
                // tombol di dalam toast. Bentuknya {url, label}. Berguna untuk
                // aksi yang memindahkan barisnya keluar dari layar: pesan yang
                // menyebut apa yang terjadi tidak banyak menolong kalau
                // datanya sendiri sudah tidak bisa dijangkau dari situ.
                'tautan' => fn () => $request->session()->get('tautan'),
                // Hasil penilaian yang mewarnai toast: `nada` berisi relevan
                // atau tidak_relevan, `sentimen` berisi salah satu dari tiga
                // nada. Dikirim sebagai data, bukan disimpulkan dari kalimat
                // pesannya. Menebak warna dengan mencari kata "relevan" di
                // dalam teks akan salah pada pesan "ditandai tidak relevan",
                // dan salahnya justru pada arah yang berlawanan.
                'nada' => fn () => $request->session()->get('nada'),
                'sentimen' => fn () => $request->session()->get('sentimen'),
                // Keterangan tambahan yang tidak terbaca dari hasilnya sendiri,
                // misalnya koreksi yang barusan dicabut atau Gemini yang
                // sengaja dilewati karena keputusan manusia sudah ada.
                'catatan' => fn () => $request->session()->get('catatan'),
                // Hasil uji satu kunci Gemini. Dirender sebagai kotak di bawah
                // kuncinya sendiri, bukan sebagai toast, karena jawaban model
                // dan kalimat galat dari Google adalah isi yang ingin dibaca
                // dan dibandingkan, bukan pemberitahuan yang lewat lalu hilang.
                'ujiKunci' => fn () => $request->session()->get('ujiKunci'),
                'nonce' => fn () => $request->session()->has('sukses')
                    || $request->session()->has('galat')
                    ? (string) Str::uuid7()
                    : null,
            ],
            // Hasil pratinjau lapor pemuatan. Dibagikan lewat session, bukan
            // disimpan di komponen, supaya menyegarkan halaman membuangnya
            // alih-alih menyodorkan pratinjau basi yang sudah tidak cocok
            // dengan isi database.
            'hasilPeriksa' => fn () => $request->session()->get('hasilPeriksa'),
            // Satu sumber untuk seluruh halaman yang menampilkan sentimen.
            // Dibagikan, bukan diulang di tiap controller: dashboard yang
            // menyatakan sentimen belum tersedia sementara halaman sentimen di
            // sebelahnya tetap menampilkan angka adalah keadaan yang lebih
            // membingungkan daripada keduanya salah.
            //
            // Sejak IndoBERT dihapus, satu-satunya syarat sentimen tersedia
            // adalah kunci Gemini terpasang. Tidak ada lagi gerbang mutu yang
            // menahan, karena tidak ada lagi model lokal yang perlu diukur.
            //
            // Yang diperiksa keberadaan kunci, bukan ketersediaan kuotanya.
            // Kuota yang sedang habis menahan klasifikasi berikutnya, bukan
            // menghapus angka yang sudah terkumpul dari layar.
            'sentimen' => fn () => $this->sentimen(),
        ]);
    }

    /** @return array{tersedia: bool, alasan: string|null} */
    private function sentimen(): array
    {
        $ada = KunciGemini::query()->exists();

        return [
            'tersedia' => $ada,
            'alasan' => $ada
                ? null
                : 'Belum ada kunci API Gemini, jadi klasifikasi tidak bisa dijalankan. '
                    .'Tambahkan di halaman Pengaturan.',
        ];
    }
}
