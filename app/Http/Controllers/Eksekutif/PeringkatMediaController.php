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
            'peringkat' => $ringkasan->peringkatMedia($periode->dari, $periode->sampai, $periode->konteksId, 25),
        ]);
    }
}
