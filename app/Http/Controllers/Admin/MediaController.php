<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TierMedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanMediaRequest;
use App\Models\Media;
use App\Support\KueriTabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class MediaController extends Controller
{
    public function index(Request $request): Response
    {
        $media = KueriTabel::untuk(Media::query()->withCount('sumberFeed'), $request)
            ->cari(['nama', 'domain', 'kota'])
            ->saring(['tier' => 'tier', 'jenis' => 'jenis', 'partner' => 'partner', 'aktif' => 'aktif'])
            ->urut(['nama', 'tier', 'jenis', 'domain', 'created_at'], 'nama')
            ->halaman();

        return Inertia::render('admin/media/Index', [
            'media' => $media,
            'opsi' => self::opsiFilter(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/media/Form', ['media' => null]);
    }

    public function store(SimpanMediaRequest $request): RedirectResponse
    {
        $media = Media::create($request->validated());

        return to_route('admin.media.index')
            ->with('sukses', "Media {$media->nama} ditambahkan.");
    }

    public function edit(Media $media): Response
    {
        return Inertia::render('admin/media/Form', ['media' => $media]);
    }

    public function update(SimpanMediaRequest $request, Media $media): RedirectResponse
    {
        $media->update($request->validated());

        return to_route('admin.media.index')
            ->with('sukses', "Media {$media->nama} diperbarui.");
    }

    /**
     * Menarik seluruh sumber feed milik satu media, sekarang juga.
     *
     * Dilempar ke antrean dengan alasan yang sama seperti tombol crawl penuh
     * di halaman Log crawl: permintaan HTTP tidak boleh menunggu unduhan dari
     * server orang lain sampai PHP-FPM memutusnya di tengah jalan.
     *
     * `--paksa` dipakai karena admin yang menekan tombol ini memang sedang
     * tidak mau menunggu interval_menit. Sumber yang sudah dimatikan tetap
     * tidak ikut ditarik, penjaganya ada di CrawlFeeds.
     */
    public function crawl(Media $media): RedirectResponse
    {
        $jumlah = $media->sumberFeed()->where('aktif', true)->count();

        if ($jumlah === 0) {
            return back()->with('galat', "{$media->nama} belum punya sumber feed aktif.");
        }

        Artisan::queue('crawl:feeds', ['--media' => $media->id, '--paksa' => true]);

        return back()->with('sukses', "Crawl {$media->nama} dijalankan di latar belakang, {$jumlah} sumber.")
            ->with('catatan', 'Hasilnya muncul di halaman Log crawl beberapa saat lagi.');
    }

    public function destroy(Media $media): RedirectResponse
    {
        // Soft delete: artikel yang sudah terkumpul tetap menunjuk media ini.
        $media->delete();

        return to_route('admin.media.index')
            ->with('sukses', "Media {$media->nama} dinonaktifkan.");
    }

    /** @return array<string, list<array{nilai: string, label: string}>> */
    private static function opsiFilter(): array
    {
        return [
            'tier' => array_map(
                fn (TierMedia $t) => ['nilai' => $t->value, 'label' => ucfirst($t->value)],
                TierMedia::cases(),
            ),
            'jenis' => [
                ['nilai' => 'online', 'label' => 'Online'],
                ['nilai' => 'cetak', 'label' => 'Cetak'],
                ['nilai' => 'tv', 'label' => 'TV'],
                ['nilai' => 'radio', 'label' => 'Radio'],
            ],
            'partner' => [
                ['nilai' => 'true', 'label' => 'Partner'],
                ['nilai' => 'false', 'label' => 'Bukan partner'],
            ],
            'aktif' => [
                ['nilai' => 'true', 'label' => 'Aktif'],
                ['nilai' => 'false', 'label' => 'Nonaktif'],
            ],
        ];
    }
}
