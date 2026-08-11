<?php

namespace App\Http\Controllers\Eksekutif;

use App\Http\Controllers\Controller;
use App\Services\Agregasi\RingkasanEksekutif;
use App\Support\Periode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeringkatMediaController extends Controller
{
    public function __invoke(Request $request, RingkasanEksekutif $ringkasan): Response
    {
        $periode = Periode::dariRequest($request, $ringkasan);

        return Inertia::render('eksekutif/PeringkatMedia', [
            ...$periode->untukInertia(),
            // Seluruh media terdaftar, termasuk yang tidak memuat satu berita
            // pun pada rentang ini. Halaman ini satu satunya tempat pimpinan
            // bisa melihat siapa yang diam, dan daftar yang dipotong dua puluh
            // lima teratas menyembunyikan justru bagian itu.
            'peringkat' => $ringkasan->peringkatMedia($periode->dari, $periode->sampai, null, termasukTanpaBerita: true),
        ]);
    }
}
