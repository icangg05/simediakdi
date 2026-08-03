<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArtikelPortalResource;
use App\Models\Artikel;
use App\Support\KueriTabel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Berita saya": artikel media ini yang tertangkap crawler.
 *
 * Dibentuk lewat ArtikelPortalResource, bukan `$artikel->only([...])` yang
 * ditulis di sini. Satu tempat yang memutuskan field apa yang boleh dilihat
 * media berarti satu tempat yang perlu diperiksa saat menambah kolom baru.
 */
class BeritaController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $artikel = KueriTabel::untuk(Artikel::query()->with('media:id,nama'), $request)
            ->cari(['judul'])
            ->saring(['status_dedup' => 'status_dedup'])
            ->urut(['judul', 'diambil_at', 'dipublikasikan_at'], 'diambil_at', 'desc')
            ->halaman();

        return Inertia::render('portal/Berita', [
            'artikel' => $artikel->through(fn (Artikel $a) => (new ArtikelPortalResource($a))->resolve()),
            'opsi' => [
                'status_dedup' => [
                    ['nilai' => 'asli', 'label' => 'Asli'],
                    ['nilai' => 'salinan', 'label' => 'Salinan'],
                ],
            ],
        ]);
    }
}
