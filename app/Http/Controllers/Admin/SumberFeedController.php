<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TipeSumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanSumberFeedRequest;
use App\Models\Media;
use App\Models\SumberFeed;
use App\Support\KueriTabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SumberFeedController extends Controller
{
    public function index(Request $request): Response
    {
        $sumberFeed = KueriTabel::untuk(SumberFeed::query()->with('media:id,nama'), $request)
            ->cari(['nama', 'url'])
            ->saring(['tipe' => 'tipe', 'aktif' => 'aktif', 'media' => 'media_id'])
            ->urut(['nama', 'tipe', 'dijalankan_terakhir_at', 'gagal_berturut'], 'nama')
            ->halaman();

        return Inertia::render('admin/sumber-feed/Index', [
            'sumberFeed' => $sumberFeed,
            'opsi' => [
                'tipe' => array_map(
                    fn (TipeSumber $t) => ['nilai' => $t->value, 'label' => $t->label()],
                    TipeSumber::cases(),
                ),
                'aktif' => [
                    ['nilai' => 'true', 'label' => 'Aktif'],
                    ['nilai' => 'false', 'label' => 'Nonaktif'],
                ],
                'media' => self::opsiMedia(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/sumber-feed/Form', [
            'sumberFeed' => null,
            'daftarMedia' => self::opsiMedia(),
        ]);
    }

    public function store(SimpanSumberFeedRequest $request): RedirectResponse
    {
        $sumber = SumberFeed::create($request->validated());

        return to_route('admin.sumber-feed.index')
            ->with('sukses', "Sumber {$sumber->nama} ditambahkan.");
    }

    public function edit(SumberFeed $sumberFeed): Response
    {
        return Inertia::render('admin/sumber-feed/Form', [
            'sumberFeed' => $sumberFeed,
            'daftarMedia' => self::opsiMedia(),
        ]);
    }

    public function update(SimpanSumberFeedRequest $request, SumberFeed $sumberFeed): RedirectResponse
    {
        $sumberFeed->update($request->validated());

        // Admin yang mengaktifkan ulang sumber berarti sudah memperbaiki
        // penyebabnya; hitungan gagal dimulai dari nol lagi (F-07).
        if ($sumberFeed->wasChanged('aktif') && $sumberFeed->aktif) {
            $sumberFeed->update(['gagal_berturut' => 0, 'pesan_error_terakhir' => null]);
        }

        return to_route('admin.sumber-feed.index')
            ->with('sukses', "Sumber {$sumberFeed->nama} diperbarui.");
    }

    public function destroy(SumberFeed $sumberFeed): RedirectResponse
    {
        $nama = $sumberFeed->nama;
        $sumberFeed->delete();

        return to_route('admin.sumber-feed.index')->with('sukses', "Sumber {$nama} dihapus.");
    }

    /** @return list<array{nilai: string, label: string}> */
    private static function opsiMedia(): array
    {
        return Media::query()
            ->where('aktif', true)
            ->orderBy('nama')
            ->get(['id', 'nama'])
            ->map(fn (Media $m) => ['nilai' => (string) $m->id, 'label' => $m->nama])
            ->all();
    }
}
