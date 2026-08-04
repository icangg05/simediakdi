<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PantauPelatihanRelevansi;
use App\Models\PelatihanModelRelevansi;
use App\Models\SnapshotDatasetRelevansi;
use App\Services\Relevance\RelevanceTrainingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class RelevanceTrainingController extends Controller
{
    public function store(Request $request, RelevanceTrainingService $service): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:150'],
            'snapshot_dataset_relevansi_id' => ['required', 'integer', 'exists:snapshot_dataset_relevansi,id'],
            'epoch' => ['required', 'numeric', 'min:1', 'max:20'],
            'batch_size' => ['required', 'integer', 'min:1', 'max:32'],
            'gradient_accumulation' => ['required', 'integer', 'min:1', 'max:32'],
            'learning_rate' => ['required', 'numeric', 'min:0.000001', 'max:0.01'],
            'max_length' => ['required', 'integer', 'min:64', 'max:512'],
            'class_weighting' => ['required', 'boolean'],
            'random_seed' => ['required', 'integer', 'min:0'],
        ]);

        $snapshot = SnapshotDatasetRelevansi::findOrFail($data['snapshot_dataset_relevansi_id']);

        try {
            $run = $service->mulai($snapshot, $data, $request->user());
        } catch (Throwable $e) {
            return back()->with('galat', $e->getMessage());
        }

        PantauPelatihanRelevansi::dispatch($run->id);

        return back()->with('sukses', "Pelatihan {$run->nama} dimulai. Kemajuannya menyegarkan sendiri di halaman ini.");
    }

    public function batalkan(
        PelatihanModelRelevansi $pelatihan,
        RelevanceTrainingService $service,
    ): RedirectResponse {
        if ($pelatihan->selesai()) {
            return back()->with('galat', 'Pelatihan ini sudah berhenti.');
        }

        $service->batalkan($pelatihan);

        return back()->with('sukses', 'Permintaan pembatalan dikirim. Pelatihan berhenti setelah langkah yang sedang jalan selesai.');
    }
}
