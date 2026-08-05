<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
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
            'flash' => [
                'sukses' => fn () => $request->session()->get('sukses'),
                'galat' => fn () => $request->session()->get('galat'),
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
            'sentimen' => [
                'tersedia' => filled(config('ai.providers.gemini.key')),
                'alasan' => filled(config('ai.providers.gemini.key'))
                    ? null
                    : 'GEMINI_API_KEY belum diisi, jadi klasifikasi tidak bisa dijalankan.',
            ],
        ]);
    }
}
