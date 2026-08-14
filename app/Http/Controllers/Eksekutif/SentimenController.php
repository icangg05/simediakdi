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
            // Deret kedua, dipecah per media. Grafik batang beranimasi memakai
            // ini, dan sumbunya nama media, bukan tanggal.
            'deretMedia' => $ringkasan->deretMedia($periode->dari, $periode->sampai),
            // Tiga nada, sepuluh berita masing-masing, dalam rentang yang sedang
            // dipilih. Halaman ini memang halaman rincian nada, jadi ketiganya
            // berhak atas daftarnya sendiri, bukan hanya yang negatif.
            'beritaNegatif' => $this->beritaBerlabel($periode, 'negatif'),
            'beritaNetral' => $this->beritaBerlabel($periode, 'netral'),
            'beritaPositif' => $this->beritaBerlabel($periode, 'positif'),
        ]);
    }

    /**
     * Berita bernada tertentu, terbaru lebih dulu.
     *
     * Sepuluh per nada. Tiga daftar berdampingan, dan batas yang lebih besar
     * membuat kolom pertama jauh lebih panjang daripada dua lainnya tanpa ada
     * yang bisa dibaca dari selisih itu.
     *
     * @return list<array<string, mixed>>
     */
    private function beritaBerlabel(Periode $periode, string $label): array
    {
        return $this->artikel($periode)
            ->whereHas('analisisSentimen', fn ($q) => $q
                ->where('relevan', true)
                ->where('label_efektif', $label))
            ->limit(10)
            ->get(['id', 'media_id', 'judul', 'url', 'diambil_at'])
            ->map($this->bentuk(...))
            ->all();
    }

    private function artikel(Periode $periode): Builder
    {
        return Artikel::query()
            // Baris analisisnya ikut dimuat karena alasan model atas labelnya
            // tampil di tiap baris berita, sama seperti di dashboard. Tanpa
            // eager load ini, dua puluh baris berarti dua puluh kueri.
            ->with(['media:id,nama,partner', 'analisisSentimen' => fn ($q) => $q->where('relevan', true)])
            ->terbitAntara($periode->mulaiUtc(), $periode->akhirUtc())
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
            'media_partner' => (bool) $artikel->media?->partner,
            'diambil_at' => $artikel->diambil_at,
            // Alasan model atas label yang diberikannya. Untuk artikel relevan
            // kolom ini sudah ditimpa tahap sentimen, jadi isinya menjelaskan
            // nadanya. Kosong pada baris analisis lama.
            'ringkasan_ai' => $artikel->analisisSentimen->first()?->reason_summary,
        ];
    }
}
