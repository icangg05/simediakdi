<?php

namespace App\Http\Controllers\Eksekutif;

use App\Http\Controllers\Controller;
use App\Services\Agregasi\RingkasanEksekutif;
use App\Support\Periode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IsuController extends Controller
{
    public function __invoke(Request $request, RingkasanEksekutif $ringkasan): Response
    {
        $periode = Periode::dariRequest($request, $ringkasan);

        return Inertia::render('eksekutif/Isu', [
            ...$periode->untukInertia(),
            'istilah' => $this->istilah($periode),
        ]);
    }

    /**
     * Istilah pada periode, diurutkan menurut jumlah artikel.
     *
     * Kolom `sentimen_dominan` yang membuat halaman ini berguna: "banjir" naik
     * 300% tidak bermakna sampai diketahui 85% artikel yang memuatnya bernada
     * negatif.
     *
     * @return list<array<string, mixed>>
     */
    private function istilah(Periode $periode): array
    {
        return DB::table('kata_kunci_periode')
            ->when(
                $periode->konteksId,
                fn ($q) => $q->where('konteks_pantauan_id', $periode->konteksId),
                fn ($q) => $q->whereNull('konteks_pantauan_id'),
            )
            ->where('granularitas', 'harian')
            ->whereBetween('periode_mulai', [$periode->dari->toDateString(), $periode->sampai->toDateString()])
            ->groupBy('istilah')
            ->orderByRaw('sum(jumlah_artikel) DESC')
            ->limit(60)
            ->get([
                'istilah',
                DB::raw('sum(frekuensi)::int AS frekuensi'),
                DB::raw('sum(jumlah_artikel)::int AS jumlah_artikel'),
                DB::raw('round(max(skor_lonjakan)::numeric, 2) AS skor_lonjakan'),
                DB::raw('mode() WITHIN GROUP (ORDER BY sentimen_dominan) AS sentimen_dominan'),
            ])
            ->map(fn ($b) => (array) $b)
            ->all();
    }
}
