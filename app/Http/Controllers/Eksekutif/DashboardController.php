<?php

namespace App\Http\Controllers\Eksekutif;

use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Models\RiwayatAlert;
use App\Services\Agregasi\RingkasanEksekutif;
use App\Support\Periode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman paling penting di seluruh sistem, dan satu-satunya yang dilihat
 * pimpinan. Dirancang mobile lebih dulu (dokumen 04 bagian C.1).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, RingkasanEksekutif $ringkasan): Response
    {
        $periode = Periode::dariRequest($request, $ringkasan);

        return Inertia::render('eksekutif/Dashboard', [
            ...$periode->untukInertia(),
            'kpi' => $ringkasan->kpi($periode->dari, $periode->sampai),
            'deret' => $ringkasan->deretHarian($periode->dari, $periode->sampai),
            'isuTeratas' => $this->isuTeratas($periode),
            'beritaTerbaru' => $this->beritaTerbaru($periode),
            'peringatan' => $this->peringatan(),
            // Footer transparansi. Baris ini yang membuat pengguna berpikir
            // "sistemnya memang tidak sempurna dan itu diakui" saat menemukan
            // satu label yang jelas salah, dan itu pasti terjadi.
        ]);
    }

    /**
     * Tiga isu teratas menurut skor lonjakan. Bukan word cloud di halaman ini,
     * word cloud disimpan untuk halaman isu.
     *
     * @return list<array<string, mixed>>
     */
    private function isuTeratas(Periode $periode): array
    {
        return DB::table('kata_kunci_periode')
            ->where('granularitas', 'harian')
            ->whereBetween('periode_mulai', [$periode->dari->toDateString(), $periode->sampai->toDateString()])
            ->groupBy('istilah')
            ->orderByRaw('sum(jumlah_artikel) DESC')
            ->limit(3)
            ->get([
                'istilah',
                DB::raw('sum(jumlah_artikel)::int AS jumlah_artikel'),
                DB::raw('max(skor_lonjakan) AS skor_lonjakan'),
                DB::raw('mode() WITHIN GROUP (ORDER BY sentimen_dominan) AS sentimen_dominan'),
            ])
            ->map(fn ($b) => (array) $b)
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function beritaTerbaru(Periode $periode): array
    {
        return Artikel::query()
            ->with(['media:id,nama', 'analisisSentimen' => fn ($q) => $q
                ->where('relevan', true),
            ])
            ->whereBetween('diambil_at', [$periode->mulaiUtc(), $periode->akhirUtc()])
            ->orderByDesc('diambil_at')
            ->limit(5)
            ->get(['id', 'media_id', 'judul', 'url', 'diambil_at'])
            ->map(fn (Artikel $a) => [
                'id' => $a->id,
                'judul' => $a->judul,
                'url' => $a->url,
                'media' => $a->media?->nama,
                'diambil_at' => $a->diambil_at,
                'label' => $a->analisisSentimen->first()?->label_efektif?->value,
                'perlu_review' => (bool) $a->analisisSentimen->first()?->perlu_review,
            ])
            ->all();
    }

    /**
     * Kartu peringatan hanya dirender kalau ada isinya. Kartu kosong bertuliskan
     * "tidak ada peringatan" menghabiskan ruang layar yang berharga.
     *
     * @return array<string, mixed>|null
     */
    private function peringatan(): ?array
    {
        $baris = RiwayatAlert::query()
            ->whereNull('dibaca_at')
            ->where('dipicu_at', '>=', now()->subDay())
            ->orderByDesc('dipicu_at')
            ->get(['id', 'ringkasan', 'dipicu_at']);

        return $baris->isEmpty() ? null : [
            'jumlah' => $baris->count(),
            'terbaru' => $baris->first()->ringkasan,
            'dipicu_at' => $baris->first()->dipicu_at,
        ];
    }
}
