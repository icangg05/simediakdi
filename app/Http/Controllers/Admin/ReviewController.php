<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnalisisSentimen as BarisAnalisis;
use App\Models\Artikel;
use App\Services\Ai\DTO\HasilKlasifikasi;
use App\Services\Ai\KlasifikasiArtikel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Antrean Klasifikasi, `/admin/review`.
 *
 * Satu halaman untuk seluruh perjalanan sebuah berita: masuk dari crawler,
 * diklasifikasi Gemini lewat tombol, lalu dikoreksi manusia bila perlu.
 * Sebelumnya pekerjaan ini terpecah ke tiga halaman (Antrean Review,
 * Pelabelan, Laboratorium Relevansi) yang tumbuh dari alur IndoBERT.
 *
 * Klasifikasi dijalankan sinkron, satu artikel satu klik. Itu keputusan
 * sementara yang disengaja: selama prompt masih disetel, hasil yang muncul
 * seketika di layar jauh lebih cepat dinilai benar atau salah daripada hasil
 * yang muncul beberapa menit kemudian di antrean. Memindahkannya ke latar
 * belakang cukup mengganti pemanggilan langsung menjadi dispatch job.
 */
class ReviewController extends Controller
{
    /**
     * Status yang bisa disaring, beserta artinya bagi admin.
     *
     * Ditulis di sini, bukan di Vue, supaya daftar status yang boleh muncul di
     * filter tidak pernah berbeda dari daftar yang dipahami kueri.
     */
    private const SARINGAN = [
        'isi_diambil' => 'Belum diklasifikasi',
        'perlu_review' => 'Menunggu review',
        'dianalisis' => 'Relevan, sentimen berjalan',
        'selesai' => 'Selesai',
        'tidak_relevan' => 'Tidak relevan',
    ];

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        if (! array_key_exists($status, self::SARINGAN)) {
            $status = 'isi_diambil';
        }

        return Inertia::render('admin/Review', [
            'status' => $status,
            'saringan' => $this->saringan(),
            'pantauan' => config('pantauan.nama'),
            'artikel' => $this->daftar($request, $status),
        ]);
    }

    /**
     * Menjalankan Gemini untuk satu artikel, sekarang juga.
     *
     * Galat penyedia tidak dilempar ke halaman sebagai layar 500. Rate limit
     * dan gangguan koneksi adalah hal yang wajar terjadi di free tier, dan
     * admin yang melihatnya butuh kalimat yang bisa ditindaklanjuti, bukan
     * stack trace.
     */
    public function klasifikasi(Artikel $artikel, KlasifikasiArtikel $klasifikasi): RedirectResponse
    {
        if ($artikel->isi === null || trim($artikel->isi) === '') {
            return back()->with('galat', 'Artikel ini belum punya isi, jadi tidak ada yang bisa dinilai.');
        }

        try {
            $hasil = $klasifikasi->jalankan($artikel);
        } catch (Throwable $galat) {
            report($galat);

            return back()->with('galat', 'Gemini gagal dipanggil: '.$galat->getMessage());
        }

        return back()->with('sukses', $this->ringkasan($hasil));
    }

    /**
     * Keputusan manusia. Tidak pernah ditimpa klasifikasi ulang (F-13).
     */
    public function store(Request $request, KlasifikasiArtikel $klasifikasi): RedirectResponse
    {
        $data = $request->validate([
            'analisis_id' => ['required', 'integer', 'exists:analisis_sentimen,id'],
            'relevan' => ['required', 'boolean'],
        ]);

        $analisis = BarisAnalisis::findOrFail($data['analisis_id']);
        $artikel = Artikel::withoutGlobalScopes()->findOrFail($analisis->artikel_id);

        DB::transaction(function () use ($request, $analisis, $artikel, $data) {
            // `relevan_manual` adalah penandanya, `relevan` adalah nilainya.
            // Tanpa penanda terpisah, klasifikasi ulang menimpa kolom yang sama
            // dan keputusan manusia hilang tanpa jejak.
            $analisis->update([
                'relevan' => $data['relevan'],
                'relevan_manual' => $data['relevan'],
                'relevan_dikoreksi_oleh' => $request->user()->id,
                'relevan_dikoreksi_at' => now(),
            ]);

            $artikel->update([
                'status_proses' => $data['relevan'] ? 'dianalisis' : 'tidak_relevan',
            ]);
        });

        if (! $data['relevan']) {
            return back()->with('sukses', 'Ditandai tidak relevan dan dikeluarkan dari dashboard.');
        }

        // Sentimen dijalankan di luar transaksi. Panggilan Gemini memakan
        // detikan, dan menahan transaksi database selama itu mengunci baris
        // lebih lama daripada perlunya.
        try {
            $klasifikasi->jalankanSentimen($artikel);
        } catch (Throwable $galat) {
            report($galat);

            return back()->with('galat', 'Ditandai relevan, tetapi sentimennya gagal dinilai: '
                .$galat->getMessage());
        }

        return back()->with('sukses', 'Ditandai relevan dan sentimennya sudah dinilai.');
    }

    /** @param list<HasilKlasifikasi> $hasil */
    private function ringkasan(array $hasil): string
    {
        if ($hasil === []) {
            return 'Artikel ini sudah punya keputusan manusia, jadi Gemini tidak dipanggil.';
        }

        return 'Selesai dinilai: '.implode(', ', array_map(
            fn (HasilKlasifikasi $satu): string => $satu->label,
            $hasil,
        )).'.';
    }

    /** @return list<array{nilai: string, label: string, jumlah: int}> */
    private function saringan(): array
    {
        $jumlah = Artikel::withoutGlobalScopes()
            ->selectRaw('status_proses, count(*) as jumlah')
            ->groupBy('status_proses')
            ->pluck('jumlah', 'status_proses');

        return collect(self::SARINGAN)
            ->map(fn (string $label, string $nilai): array => [
                'nilai' => $nilai,
                'label' => $label,
                'jumlah' => (int) ($jumlah[$nilai] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Daftar artikel pada status terpilih, terbaru dulu.
     *
     * Artikel salinan dikecualikan. Ia sudah menunjuk induknya, dan menilai
     * keduanya berarti membayar Gemini dua kali untuk berita yang sama.
     *
     * @return array<string, mixed>
     */
    private function daftar(Request $request, string $status): array
    {
        $artikel = Artikel::withoutGlobalScopes()
            ->with(['media:id,nama', 'analisisSentimen'])
            ->asli()
            ->where('status_proses', $status)
            ->when($request->filled('cari'), fn ($kueri) => $kueri->where(
                'judul', 'ilike', '%'.$request->string('cari')->toString().'%',
            ))
            ->latest('diambil_at')
            ->paginate(20)
            ->withQueryString();

        $artikel->through(function (Artikel $satu): array {
            $analisis = $satu->relationLoaded('analisisSentimen')
                ? $satu->analisisSentimen->first()
                : null;

            return [
                'id' => $satu->id,
                'judul' => $satu->judul,
                'url' => $satu->url,
                'media' => $satu->media?->nama,
                'diambil_at' => $satu->diambil_at,
                'status_proses' => $satu->status_proses,
                'analisis' => $analisis === null ? null : [
                    'id' => $analisis->id,
                    'relevan' => $analisis->relevan,
                    'relevan_manual' => $analisis->relevan_manual,
                    'label_model' => $analisis->label_model,
                    'label_manual' => $analisis->label_manual,
                    'label_efektif' => $analisis->label_efektif,
                    'perlu_review' => $analisis->perlu_review,
                    'provider' => $analisis->provider,
                    'reason_code' => $analisis->reason_code,
                    'reason_summary' => $analisis->reason_summary,
                    'evidence' => $analisis->evidence,
                ],
            ];
        });

        return $artikel->toArray();
    }
}
