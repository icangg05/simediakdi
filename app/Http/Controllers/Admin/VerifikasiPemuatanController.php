<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StatusVerifikasi;
use App\Http\Controllers\Controller;
use App\Models\Pemuatan;
use App\Support\KueriTabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Antrean verifikasi laporan pemuatan.
 *
 * Yang masuk antrean hanya `laporan_media`. Pemuatan `otomatis` sudah
 * terverifikasi sejak dibuat, karena sistem sendiri yang menemukannya lewat
 * crawler dan tidak ada klaim pihak berkepentingan yang perlu diperiksa.
 */
class VerifikasiPemuatanController extends Controller
{
    public function index(Request $request): Response
    {
        $pemuatan = KueriTabel::untuk(
            Pemuatan::query()
                ->where('sumber_catatan', '!=', 'otomatis')
                ->with(['media:id,nama', 'kontrak:id,judul,nomor', 'pelapor:id,name']),
            $request,
        )
            ->cari(['judul', 'url'])
            ->saring(['status' => 'status_verifikasi', 'media' => 'media_id'])
            ->urut(['tanggal_muat', 'created_at'], 'created_at', 'desc')
            ->halaman();

        return Inertia::render('admin/pemuatan/Index', [
            'pemuatan' => $pemuatan->through(fn (Pemuatan $p) => [
                ...$p->only(['id', 'url', 'judul', 'sumber_catatan', 'status_ekstraksi', 'alasan_penolakan']),
                'tanggal_muat' => $p->tanggal_muat?->toDateString(),
                'status_verifikasi' => $p->status_verifikasi->value,
                'media' => $p->media?->only(['id', 'nama']),
                'kontrak' => $p->kontrak?->only(['id', 'judul', 'nomor']),
                'pelapor' => $p->pelapor?->name,
                'punya_arsip' => $p->arsip_diambil_at !== null,
                'punya_gambar' => $p->arsip_screenshot_path !== null || $p->bukti_path !== null,
                'arsip_diambil_at' => $p->arsip_diambil_at,
                // Ringkasan saja. Isi arsip utuh dilindungi aturan hak cipta
                // yang sama dengan isi artikel (dokumen 01 bagian 6).
                'cuplikan_arsip' => $p->arsip_teks !== null ? mb_substr($p->arsip_teks, 0, 300) : null,
            ]),
            'jumlahMenunggu' => Pemuatan::query()
                ->where('sumber_catatan', '!=', 'otomatis')
                ->where('status_verifikasi', StatusVerifikasi::Menunggu)
                ->count(),
            'opsi' => [
                'status' => [
                    ['nilai' => 'menunggu', 'label' => 'Menunggu'],
                    ['nilai' => 'terverifikasi', 'label' => 'Terverifikasi'],
                    ['nilai' => 'ditolak', 'label' => 'Ditolak'],
                ],
            ],
        ]);
    }

    public function update(Request $request, Pemuatan $pemuatan): RedirectResponse
    {
        $data = $request->validate([
            'status_verifikasi' => ['required', 'in:terverifikasi,ditolak'],
            // Constraint database menegakkan aturan yang sama. Divalidasi di
            // sini juga supaya admin mendapat pesan yang bisa dibaca, bukan
            // galat SQL.
            'alasan_penolakan' => ['required_if:status_verifikasi,ditolak', 'nullable', 'string', 'max:1000'],
        ], [
            'alasan_penolakan.required_if' => 'Sebutkan alasan penolakannya. Media membacanya di portal dan harus tahu apa yang perlu diperbaiki.',
        ]);

        $pemuatan->update([
            'status_verifikasi' => $data['status_verifikasi'],
            'alasan_penolakan' => $data['status_verifikasi'] === 'ditolak' ? $data['alasan_penolakan'] : null,
            'diverifikasi_oleh' => $request->user()->id,
            'diverifikasi_at' => now(),
        ]);

        return back()->with('sukses', $data['status_verifikasi'] === 'ditolak'
            ? 'Laporan ditolak. Media melihat alasannya di portal.'
            : 'Laporan diverifikasi dan dihitung ke kontrak.');
    }

    /**
     * Bukti disajikan lewat rute ini, bukan dari public/.
     *
     * Berkasnya sengaja disimpan di luar direktori publik, jadi satu-satunya
     * jalan membacanya adalah rute yang melewati middleware peran.
     */
    public function bukti(Pemuatan $pemuatan): StreamedResponse
    {
        $jalur = $pemuatan->arsip_screenshot_path ?? $pemuatan->bukti_path;

        abort_if($jalur === null, 404, 'Belum ada bukti gambar untuk pemuatan ini.');

        return Storage::disk('local')->response($jalur);
    }
}
