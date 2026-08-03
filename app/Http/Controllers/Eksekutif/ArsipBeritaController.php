<?php

namespace App\Http\Controllers\Eksekutif;

use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Models\Media;
use App\Services\Agregasi\RingkasanEksekutif;
use App\Support\KueriTabel;
use App\Support\Periode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArsipBeritaController extends Controller
{
    public function __invoke(Request $request, RingkasanEksekutif $ringkasan): Response
    {
        $periode = Periode::dariRequest($request, $ringkasan);

        $kueri = Artikel::query()
            ->asli()
            ->with(['media:id,nama', 'analisisSentimen' => fn ($q) => $q
                ->when($periode->konteksId, fn ($k) => $k->where('konteks_pantauan_id', $periode->konteksId))
                ->where('relevan', true),
            ])
            ->whereBetween('diambil_at', [$periode->mulaiUtc(), $periode->akhirUtc()])
            ->when($request->query('sentimen'), fn ($q, $label) => $q->whereHas(
                'analisisSentimen',
                fn ($s) => $s->where('relevan', true)
                    ->whereIn('label_efektif', explode(',', $label))
                    ->when($periode->konteksId, fn ($k) => $k->where('konteks_pantauan_id', $periode->konteksId)),
            ))
            // Filter istilah dari halaman isu: klik satu istilah membuka arsip
            // yang sudah tersaring.
            ->when($request->query('istilah'), fn ($q, $istilah) => $q->where(function ($w) use ($istilah) {
                $w->where('judul', 'ilike', '%'.$istilah.'%')
                    ->orWhere('isi', 'ilike', '%'.$istilah.'%');
            }));

        $artikel = KueriTabel::untuk($kueri, $request)
            ->cari(['judul', 'penulis'])
            ->saring(['media' => 'media_id'])
            ->urut(['judul', 'diambil_at'], 'diambil_at', 'desc')
            ->halaman(20);

        return Inertia::render('eksekutif/ArsipBerita', [
            ...$periode->untukInertia(),
            'artikel' => $artikel->through(fn (Artikel $a) => [
                'id' => $a->id,
                'judul' => $a->judul,
                'url' => $a->url,
                'media' => $a->media?->nama,
                'diambil_at' => $a->diambil_at,
                'label' => $a->analisisSentimen->first()?->label_efektif?->value,
                'perlu_review' => (bool) $a->analisisSentimen->first()?->perlu_review,
            ]),
            'istilah' => $request->query('istilah'),
            'opsi' => [
                'media' => Media::query()->orderBy('nama')->get(['id', 'nama'])
                    ->map(fn (Media $m) => ['nilai' => (string) $m->id, 'label' => $m->nama])->all(),
            ],
        ]);
    }
}
