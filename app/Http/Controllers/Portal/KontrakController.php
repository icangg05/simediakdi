<?php

namespace App\Http\Controllers\Portal;

use App\Enums\StatusVerifikasi;
use App\Http\Controllers\Controller;
use App\Models\Kontrak;
use App\Models\Pemuatan;
use App\Services\Kontrak\PencocokPemuatan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Kontrak saya", wireframe dokumen 04 bagian C.4.
 *
 * **Halaman ini tidak menampilkan skor sentimen, dan itu keputusan produk
 * (dokumen 01 bagian 8), bukan kelalaian.** Kalau media bisa melihat nilai
 * sentimennya, sebagian akan menyesuaikan gaya penulisan agar terbaca positif
 * oleh model, dan dalam beberapa bulan data sentimen mengukur kepatuhan
 * terhadap model, bukan nada pemberitaan.
 *
 * Enam bulan dari sekarang akan ada yang meminta fiturnya ditambahkan. Alasan
 * penolakannya ada di paragraf di atas.
 */
class KontrakController extends Controller
{
    public function __invoke(PencocokPemuatan $pencocok): Response
    {
        $kontrak = Kontrak::query()
            ->whereIn('status', ['aktif', 'selesai'])
            ->orderByRaw("status = 'aktif' desc")
            ->orderByDesc('tanggal_akhir')
            ->get();

        return Inertia::render('portal/Kontrak', [
            'kontrak' => $kontrak->map(fn (Kontrak $k) => [
                ...$k->only(['id', 'nomor', 'judul', 'jenis', 'status', 'target_pemuatan']),
                'tanggal_mulai' => $k->tanggal_mulai,
                'tanggal_akhir' => $k->tanggal_akhir,
                'progres' => $pencocok->progres($k),
            ])->all(),
            'pemuatan' => $this->pemuatan(),
            // Ditolak dipisah, tidak dicampur ke daftar utama. Laporan yang
            // ditolak butuh tindakan media, dan yang butuh tindakan tidak boleh
            // tenggelam di antara 200 baris yang tidak butuh apa-apa.
            'ditolak' => $this->pemuatan(StatusVerifikasi::Ditolak),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function pemuatan(?StatusVerifikasi $status = null): array
    {
        return Pemuatan::query()
            ->when($status, fn ($q) => $q->where('status_verifikasi', $status))
            ->when(! $status, fn ($q) => $q->where('status_verifikasi', '!=', StatusVerifikasi::Ditolak))
            ->with('kontrak:id,judul')
            ->orderByDesc('tanggal_muat')
            ->limit(200)
            ->get()
            ->map(fn (Pemuatan $p) => [
                ...$p->only(['id', 'url', 'judul', 'sumber_catatan', 'alasan_penolakan']),
                'tanggal_muat' => $p->tanggal_muat?->toDateString(),
                'status_verifikasi' => $p->status_verifikasi->value,
                'kontrak' => $p->kontrak?->only(['id', 'judul']),
            ])
            ->all();
    }
}
