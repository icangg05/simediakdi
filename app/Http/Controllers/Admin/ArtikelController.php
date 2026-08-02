<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Models\Media;
use App\Support\KueriTabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArtikelController extends Controller
{
    public function index(Request $request): Response
    {
        $kueri = Artikel::query()
            ->with('media:id,nama')
            ->select([
                'id', 'media_id', 'judul', 'url', 'penulis', 'jumlah_kata',
                'dipublikasikan_at', 'diambil_at', 'status_dedup', 'status_proses',
                'artikel_induk_id', 'pesan_gagal',
            ])
            ->withCount('salinan');

        $this->saringTanggal($kueri, $request);

        $artikel = KueriTabel::untuk($kueri, $request)
            ->cari(['judul', 'penulis'])
            ->saring([
                'media' => 'media_id',
                'dedup' => 'status_dedup',
                'proses' => 'status_proses',
            ])
            ->urut(['judul', 'diambil_at', 'dipublikasikan_at', 'jumlah_kata'], 'diambil_at', 'desc')
            ->halaman();

        return Inertia::render('admin/artikel/Index', [
            'artikel' => $artikel,
            'opsi' => [
                'media' => Media::query()->orderBy('nama')->get(['id', 'nama'])
                    ->map(fn (Media $m) => ['nilai' => (string) $m->id, 'label' => $m->nama])->all(),
                'dedup' => [
                    ['nilai' => 'asli', 'label' => 'Asli'],
                    ['nilai' => 'salinan', 'label' => 'Salinan'],
                ],
                'proses' => [
                    ['nilai' => 'mentah', 'label' => 'Mentah'],
                    ['nilai' => 'isi_diambil', 'label' => 'Isi diambil'],
                    ['nilai' => 'dianalisis', 'label' => 'Dianalisis'],
                    ['nilai' => 'selesai', 'label' => 'Selesai'],
                    ['nilai' => 'gagal', 'label' => 'Gagal'],
                ],
            ],
            'tanggal' => [
                'dari' => $request->query('dari'),
                'sampai' => $request->query('sampai'),
            ],
        ]);
    }

    /**
     * Rentang tanggal dibaca pada `diambil_at`, bukan `dipublikasikan_at`.
     * Tanggal dari feed bisa null atau salah, sedangkan waktu pengambilan
     * selalu terisi — dan itu yang dipakai seluruh grafik harian.
     */
    private function saringTanggal(Builder $kueri, Request $request): void
    {
        $zona = config('app.timezone');

        if ($dari = $request->query('dari')) {
            $kueri->where('diambil_at', '>=', \Carbon\CarbonImmutable::parse($dari, $zona)->startOfDay());
        }

        if ($sampai = $request->query('sampai')) {
            $kueri->where('diambil_at', '<=', \Carbon\CarbonImmutable::parse($sampai, $zona)->endOfDay());
        }
    }
}
