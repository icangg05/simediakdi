<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanEntitasRequest;
use App\Models\Entitas;
use App\Support\KueriTabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EntitasController extends Controller
{
    public function index(Request $request): Response
    {
        $entitas = KueriTabel::untuk(
            Entitas::query()
                ->whereNull('digabung_ke')
                ->withCount('artikel'),
            $request,
        )
            ->cari(['nama', 'nama_normal'])
            ->saring(['jenis' => 'jenis'])
            ->urut(['nama', 'jenis', 'artikel_count'], 'artikel_count', 'desc')
            ->halaman();

        return Inertia::render('admin/entitas/Index', [
            'entitas' => $entitas->through(fn (Entitas $e) => [
                ...$e->only(['id', 'nama', 'jenis', 'artikel_count']),
                'alias' => $e->alias ?? [],
            ]),
            'opsi' => ['jenis' => self::jenis()],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/entitas/Form', [
            'entitas' => null,
            'opsiJenis' => self::jenis(),
        ]);
    }

    public function store(SimpanEntitasRequest $request): RedirectResponse
    {
        $entitas = Entitas::create($request->safe()->only(['nama', 'nama_normal', 'jenis', 'alias']));

        return to_route('admin.entitas.index')->with(
            'sukses',
            "Entitas {$entitas->nama} ditambahkan. Jalankan hitung:entitas agar artikel lama ikut tercocokkan.",
        );
    }

    public function edit(Entitas $entitas): Response
    {
        return Inertia::render('admin/entitas/Form', [
            'entitas' => [...$entitas->only(['id', 'nama', 'jenis']), 'alias' => $entitas->alias ?? []],
            'opsiJenis' => self::jenis(),
        ]);
    }

    public function update(SimpanEntitasRequest $request, Entitas $entitas): RedirectResponse
    {
        $entitas->update($request->safe()->only(['nama', 'nama_normal', 'jenis', 'alias']));

        return to_route('admin.entitas.index')->with('sukses', "Entitas {$entitas->nama} diperbarui.");
    }

    public function destroy(Entitas $entitas): RedirectResponse
    {
        $entitas->delete();

        return to_route('admin.entitas.index')->with('sukses', "Entitas {$entitas->nama} dihapus.");
    }

    /**
     * Menggabungkan entitas duplikat (F-18).
     *
     * Yang digabung tidak dihapus. Barisnya ditandai `digabung_ke` dan
     * namanya ikut menjadi alias entitas induk, supaya pencocokan berikutnya
     * mengenali ejaan lama tanpa admin perlu mengetiknya ulang. Menghapusnya
     * akan menghapus juga catatan bahwa ejaan itu pernah dipakai, dan enam
     * bulan lagi ada yang membuatnya kembali sebagai entitas baru.
     */
    public function gabungkan(Request $request, Entitas $entitas): RedirectResponse
    {
        $data = $request->validate([
            'induk_id' => ['required', 'integer', 'exists:entitas,id', 'different:'.$entitas->id],
        ], [
            'induk_id.different' => 'Entitas tidak bisa digabungkan ke dirinya sendiri.',
        ]);

        $induk = Entitas::findOrFail($data['induk_id']);

        if ($induk->digabung_ke !== null) {
            return back()->with('galat', "{$induk->nama} sendiri sudah digabungkan ke entitas lain. Pilih entitas induknya.");
        }

        DB::transaction(function () use ($entitas, $induk) {
            // Sebutan dipindahkan, bukan disalin. Artikel yang menyebut
            // keduanya sudah punya baris untuk induk, jadi bentrokan diabaikan
            // alih-alih menggagalkan penggabungan.
            DB::statement(
                'INSERT INTO artikel_entitas (artikel_id, entitas_id, jumlah_sebutan)
                 SELECT artikel_id, ?, jumlah_sebutan FROM artikel_entitas WHERE entitas_id = ?
                 ON CONFLICT (artikel_id, entitas_id)
                 DO UPDATE SET jumlah_sebutan = LEAST(32767, artikel_entitas.jumlah_sebutan + EXCLUDED.jumlah_sebutan)',
                [$induk->id, $entitas->id],
            );

            DB::table('artikel_entitas')->where('entitas_id', $entitas->id)->delete();

            $induk->update([
                'alias' => collect([...(array) ($induk->alias ?? []), $entitas->nama, ...(array) ($entitas->alias ?? [])])
                    ->unique()->values()->all(),
            ]);

            $entitas->update(['digabung_ke' => $induk->id]);
        });

        return to_route('admin.entitas.index')->with(
            'sukses',
            "{$entitas->nama} digabungkan ke {$induk->nama}. Ejaan lamanya tersimpan sebagai alias.",
        );
    }

    /** @return list<array{nilai: string, label: string}> */
    private static function jenis(): array
    {
        return [
            ['nilai' => 'orang', 'label' => 'Orang'],
            ['nilai' => 'organisasi', 'label' => 'Organisasi'],
            ['nilai' => 'opd', 'label' => 'OPD'],
            ['nilai' => 'lokasi', 'label' => 'Lokasi'],
            ['nilai' => 'program', 'label' => 'Program'],
            ['nilai' => 'lain', 'label' => 'Lain'],
        ];
    }
}
