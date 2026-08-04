<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\KonteksPantauan;
use App\Services\Relevance\RelevanceQualityGateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Antrean artikel yang skornya di antara dua ambang relevansi.
 *
 * Bedanya dengan halaman pelabelan: keputusan di sini langsung mengubah
 * dashboard, sedangkan keputusan di halaman pelabelan hanya mengubah
 * penggarisnya. Keduanya sengaja tidak digabung.
 */
class ReviewController extends Controller
{
    public function index(): Response
    {
        $konteks = KonteksPantauan::utama();

        return Inertia::render('admin/Review', [
            'artikel' => $this->berikutnya($konteks),
            'sisa' => Artikel::withoutGlobalScopes()
                ->where('status_proses', 'perlu_review')->count(),
            'ambang' => [
                'atas' => config('nlp.ambang.relevansi_atas'),
                'bawah' => config('nlp.ambang.relevansi_bawah'),
            ],
            'konteks' => $konteks?->only(['id', 'nama']),
        ]);
    }

    /**
     * Satu artikel per layar, diurutkan menurut prioritas dokumen 04 bagian C.3.1.
     *
     * Urutan pertama yang dipakai: skor paling dekat ambang atas. Di situlah
     * model paling sering salah, dan artikel yang nyaris lolos adalah yang
     * paling merugikan kalau dibiarkan salah masuk.
     *
     * @return array<string, mixed>|null
     */
    private function berikutnya(?KonteksPantauan $konteks): ?array
    {
        if ($konteks === null) {
            return null;
        }

        $atas = (float) (config('nlp.ambang.relevansi_atas') ?? 1.0);

        $artikel = Artikel::withoutGlobalScopes()
            ->with('media:id,nama')
            ->join('analisis_sentimen as s', function ($join) use ($konteks) {
                $join->on('s.artikel_id', '=', 'artikel.id')
                    ->where('s.konteks_pantauan_id', $konteks->id);
            })
            ->where('artikel.status_proses', 'perlu_review')
            ->orderByRaw('abs(coalesce(s.skor_relevansi, 0) - ?)', [$atas])
            ->select(['artikel.*', 's.id as analisis_id', 's.skor_relevansi'])
            ->first();

        if ($artikel === null) {
            return null;
        }

        return [
            'id' => $artikel->id,
            'analisis_id' => $artikel->analisis_id,
            'judul' => $artikel->judul,
            'url' => $artikel->url,
            'ringkasan' => $artikel->ringkasan,
            'isi' => mb_substr((string) $artikel->isi, 0, 1500),
            'media' => $artikel->media?->nama,
            'diambil_at' => $artikel->diambil_at,
            'skor_relevansi' => $artikel->skor_relevansi === null ? null : (float) $artikel->skor_relevansi,
            'sebutan' => $this->sebutan($artikel, $konteks),
        ];
    }

    /**
     * Berapa kali kata kunci konteks muncul, dipecah judul dan isi.
     *
     * Ditampilkan karena inilah yang paling sering menentukan jawabannya:
     * artikel yang menyebut Pemkot sekali di tengah berita tentang hal lain
     * hampir selalu tidak relevan, dan admin tidak perlu membaca seluruhnya
     * untuk tahu itu.
     *
     * @return array<string, int>
     */
    private function sebutan(Artikel $artikel, KonteksPantauan $konteks): array
    {
        $hitung = function (string $teks) use ($konteks): int {
            $teks = mb_strtolower($teks);
            $n = 0;

            foreach ($konteks->kata_kunci ?? [] as $kata) {
                $n += substr_count($teks, mb_strtolower((string) $kata));
            }

            return $n;
        };

        return [
            'judul' => $hitung($artikel->judul),
            'isi' => $hitung((string) $artikel->isi),
        ];
    }

    /**
     * Keputusan manusia. Tidak pernah ditimpa analisis ulang (F-13).
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'analisis_id' => ['required', 'integer', 'exists:analisis_sentimen,id'],
            'relevan' => ['required', 'boolean'],
            'alasan' => ['nullable', 'string', 'max:500'],
        ]);

        $analisis = AnalisisSentimen::findOrFail($data['analisis_id']);

        DB::transaction(function () use ($analisis, $data) {
            $analisis->update(['relevan' => $data['relevan']]);

            $artikel = Artikel::withoutGlobalScopes()->findOrFail($analisis->artikel_id);

            if ($data['relevan']) {
                $artikel->update(['status_proses' => 'dianalisis']);
                \App\Jobs\AnalisisSentimen::dispatch($artikel->id);

                return;
            }

            $artikel->update(['status_proses' => 'tidak_relevan']);
        });

        if (! $data['relevan']) {
            return back()->with('sukses', 'Ditandai tidak relevan dan dikeluarkan dari dashboard.');
        }

        // Job sentimen tetap didispatch dan tetap menolak sendiri selama
        // gerbang belum lulus. Pesannya yang harus jujur: mengatakan
        // "sentimennya sedang dinilai" padahal tidak ada yang dinilai membuat
        // admin menunggu hasil yang tidak akan pernah datang.
        return back()->with('sukses', app(RelevanceQualityGateService::class)->lolos()
            ? 'Ditandai relevan. Sentimennya sedang dinilai.'
            : 'Ditandai relevan. Sentimennya belum dinilai karena model relevansi belum lolos gerbang mutu.');
    }
}
