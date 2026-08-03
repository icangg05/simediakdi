<?php

namespace App\Http\Controllers\Portal;

use App\Enums\StatusVerifikasi;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArtikelPortalResource;
use App\Models\Artikel;
use App\Models\Kontrak;
use App\Models\Pemuatan;
use App\Services\Kontrak\PencocokPemuatan;
use App\Support\Waktu;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Beranda portal media.
 *
 * Seluruh kueri di sini bersandar pada global scope MilikMedia, bukan pada
 * `where('media_id', ...)` yang ditulis tangan. Bedanya penting: kalau
 * penyaringan ditulis per kueri, satu kueri yang lupa menuliskannya membocorkan
 * data media lain, dan tidak ada yang menyadarinya sampai ada yang protes.
 */
class DashboardController extends Controller
{
    public function __invoke(PencocokPemuatan $pencocok): Response
    {
        $sejak = Waktu::awalHariIni()->subDays(30);

        $kontrakAktif = Kontrak::query()
            ->where('status', 'aktif')
            ->orderBy('tanggal_akhir')
            ->get();

        return Inertia::render('portal/Dashboard', [
            'kpi' => [
                'artikel_30_hari' => Artikel::query()->where('diambil_at', '>=', $sejak)->count(),
                'pemuatan_tercatat' => Pemuatan::query()
                    ->where('status_verifikasi', StatusVerifikasi::Terverifikasi)->count(),
                'menunggu_verifikasi' => Pemuatan::query()
                    ->where('status_verifikasi', StatusVerifikasi::Menunggu)->count(),
                'ditolak' => Pemuatan::query()
                    ->where('status_verifikasi', StatusVerifikasi::Ditolak)->count(),
            ],
            'kontrak' => $kontrakAktif->map(fn (Kontrak $k) => [
                ...$k->only(['id', 'nomor', 'judul', 'jenis']),
                'tanggal_akhir' => $k->tanggal_akhir,
                'progres' => $pencocok->progres($k),
            ])->all(),
            'beritaTerbaru' => ArtikelPortalResource::collection(
                Artikel::query()->with('media:id,nama')->latest('diambil_at')->limit(5)->get()
            )->resolve(),
        ]);
    }
}
