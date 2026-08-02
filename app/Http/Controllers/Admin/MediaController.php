<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TierMedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanMediaRequest;
use App\Models\Media;
use App\Support\KueriTabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
