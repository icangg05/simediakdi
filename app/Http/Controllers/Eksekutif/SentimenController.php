<?php

namespace App\Http\Controllers\Eksekutif;

use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Services\Agregasi\RingkasanEksekutif;
use App\Support\Periode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SentimenController extends Controller
{
    public function __invoke(Request $request, RingkasanEksekutif $ringkasan): Response
    {
        $periode = Periode::dariRequest($request, $ringkasan);

        return Inertia::render('eksekutif/Sentimen', [
            ...$periode->untukInertia(),
            'kpi' => $ringkasan->kpi($periode->dari, $periode->sampai),
            'deret' => $ringkasan->deret($periode->dari, $periode->sampai),
            'beritaNegatif' => $this->beritaBerlabel($periode, 'negatif'),
            'perluReview' => $this->perluReview($periode),
        ]);
    }

    /**
     * Berita bernada tertentu, terbaru lebih dulu.
     *
     * @return list<array<string, mixed>>
     */
    private function beritaBerlabel(Periode $periode, string $label): array
    {
        return $this->artikel($periode)
            ->whereHas('analisisSentimen', fn ($q) => $q
                ->where('relevan', true)
                ->where('label_efektif', $label))
            ->limit(20)
            ->get(['id', 'media_id', 'judul', 'url', 'diambil_at'])
            ->map($this->bentuk(...))
            ->all();
    }

    /**
     * Hasil yang keyakinannya di bawah ambang. Ditampilkan terpisah, bukan
     * disembunyikan: sistem tidak boleh menyatakan hal yang tidak diketahuinya.
     *
     * @return list<array<string, mixed>>
     */
    private function perluReview(Periode $periode): array
    {
        return $this->artikel($periode)
            ->whereHas('analisisSentimen', fn ($q) => $q
                ->where('relevan', true)
                ->where('perlu_review', true))
            ->limit(10)
            ->get(['id', 'media_id', 'judul', 'url', 'diambil_at'])
            ->map($this->bentuk(...))
            ->all();
    }

    private function artikel(Periode $periode): Builder
    {
        return Artikel::query()
            ->with('media:id,nama')
            ->whereBetween('diambil_at', [$periode->mulaiUtc(), $periode->akhirUtc()])
            ->orderByDesc('diambil_at');
    }

    /** @return array<string, mixed> */
    private function bentuk(Artikel $artikel): array
    {
        return [
            'id' => $artikel->id,
            'judul' => $artikel->judul,
            'url' => $artikel->url,
            'media' => $artikel->media?->nama,
            'diambil_at' => $artikel->diambil_at,
        ];
    }
}
