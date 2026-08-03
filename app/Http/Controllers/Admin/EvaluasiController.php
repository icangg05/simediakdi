<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EvaluasiModel;
use App\Models\GoldSet;
use App\Services\Nlp\EvaluatorModel;
use Inertia\Inertia;
use Inertia\Response;

class EvaluasiController extends Controller
{
    public function __invoke(EvaluatorModel $evaluator): Response
    {
        return Inertia::render('admin/Evaluasi', [
            'riwayat' => EvaluasiModel::query()
                ->orderByDesc('dievaluasi_at')
                ->limit(20)
                ->get(),
            'goldSet' => [
                'ronde1' => GoldSet::where('ronde', 1)->count(),
                'ronde2' => GoldSet::where('ronde', 2)->count(),
                'relevan' => GoldSet::where('ronde', 1)->where('relevan_gold', true)->count(),
            ],
            'konsistensiPelabel' => $evaluator->konsistensiPelabel(),
            // Penyaring relevansi menentukan artikel mana yang masuk grafik,
            // jadi angkanya sama menentukannya dengan F1 sentimen.
            'relevansi' => $evaluator->metrikRelevansi(),
            // Angka gabungan menyembunyikan selisih antar konteks yang besar.
            'perKonteks' => $evaluator->metrikPerKonteks(),
            'ambangGerbang' => 0.65,
        ]);
    }
}
