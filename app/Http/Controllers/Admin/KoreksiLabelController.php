<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LabelSentimen;
use App\Http\Controllers\Controller;
use App\Models\AnalisisSentimen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * F-13: koreksi manusia selalu mengalahkan hasil model.
 *
 * Yang ditulis hanya `label_manual`. `label_model` tidak pernah disentuh supaya
 * selisih keduanya tetap bisa dibaca saat mengevaluasi model, dan
 * `label_efektif`, kolom generated `COALESCE(label_manual, label_model)`,
 * ikut berubah dengan sendirinya. Analisis ulang menimpa `label_model`, tapi
 * tidak akan pernah menghapus koreksi ini.
 */
class KoreksiLabelController extends Controller
{
    public function update(Request $request, AnalisisSentimen $analisis): RedirectResponse
    {
        $data = $request->validate([
            'label_manual' => ['nullable', new Enum(LabelSentimen::class)],
            'catatan_koreksi' => ['nullable', 'string', 'max:2000'],
        ], [
            'label_manual.Illuminate\Validation\Rules\Enum' => 'Label hanya boleh negatif, netral, atau positif.',
        ]);

        $label = $data['label_manual'] ?? null;

        $analisis->update([
            'label_manual' => $label,
            'catatan_koreksi' => $data['catatan_koreksi'] ?? null,
            // Dikosongkan saat koreksi dicabut, supaya jejaknya tidak menyisakan
            // pengoreksi untuk label yang sudah tidak ada.
            'dikoreksi_oleh' => $label === null ? null : $request->user()->id,
            'dikoreksi_at' => $label === null ? null : now(),
            // Koreksi manusia adalah kepastian. Menandainya masih perlu review
            // berarti meragukan orang yang baru saja memutuskan.
            'perlu_review' => $label === null ? $this->perluReviewUlang($analisis) : false,
        ]);

        return back()->with('sukses', $label === null
            ? 'Koreksi dicabut. Label kembali mengikuti hasil model.'
            : "Label dikoreksi menjadi {$label}.");
    }

    /** Saat koreksi dicabut, status review kembali ditentukan keyakinan model. */
    private function perluReviewUlang(AnalisisSentimen $analisis): bool
    {
        return $analisis->keyakinan !== null
            && $analisis->keyakinan < (float) config('nlp.ambang.sentimen');
    }
}
