<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanKonteksRequest;
use App\Models\AnalisisSentimen;
use App\Models\KonteksPantauan;
use App\Support\KueriTabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class KonteksController extends Controller
{
    public function index(Request $request): Response
    {
        $konteks = KueriTabel::untuk(
            KonteksPantauan::query()->withCount([
                'analisisSentimen as jumlah_relevan' => fn ($q) => $q->where('relevan', true),
            ]),
            $request,
        )
            ->cari(['nama', 'deskripsi'])
            ->saring(['aktif' => 'aktif'])
            ->urut(['nama', 'urutan'], 'urutan')
            ->halaman();

        return Inertia::render('admin/konteks/Index', [
            'konteks' => $konteks,
            'opsi' => [
                'aktif' => [
                    ['nilai' => 'true', 'label' => 'Aktif'],
                    ['nilai' => 'false', 'label' => 'Nonaktif'],
                ],
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/konteks/Form', ['konteks' => null]);
    }

    public function store(SimpanKonteksRequest $request): RedirectResponse
    {
        $konteks = DB::transaction(function () use ($request) {
            $data = $request->validated();

            $this->lepasUtamaLama($data['utama']);

            return KonteksPantauan::create($data);
        });

        return to_route('admin.konteks.index')
            ->with('sukses', "Konteks {$konteks->nama} ditambahkan. Artikel baru mulai dinilai terhadapnya.");
    }

    public function edit(KonteksPantauan $konteks): Response
    {
        return Inertia::render('admin/konteks/Form', ['konteks' => $konteks]);
    }

    public function update(SimpanKonteksRequest $request, KonteksPantauan $konteks): RedirectResponse
    {
        DB::transaction(function () use ($request, $konteks) {
            $data = $request->validated();

            $this->lepasUtamaLama($data['utama'], $konteks->id);

            $konteks->update($data);
        });

        return to_route('admin.konteks.index')->with('sukses', "Konteks {$konteks->nama} diperbarui.");
    }

    /**
     * Konteks tidak dihapus, hanya dinonaktifkan.
     *
     * Menghapusnya akan ikut menghapus seluruh baris analisis lewat cascade,
     * dan angka historis di dashboard berubah surut tanpa jejak. Menonaktifkan
     * menghentikan penilaian artikel baru tanpa menyentuh yang sudah ada.
     */
    public function destroy(KonteksPantauan $konteks): RedirectResponse
    {
        if ($konteks->utama) {
            return back()->with('galat', 'Konteks utama tidak dapat dinonaktifkan. Tetapkan konteks utama lain lebih dulu.');
        }

        $konteks->update(['aktif' => false]);

        $jumlah = AnalisisSentimen::where('konteks_pantauan_id', $konteks->id)->count();

        return to_route('admin.konteks.index')->with(
            'sukses',
            "Konteks {$konteks->nama} dinonaktifkan. {$jumlah} baris analisis yang sudah ada tetap tersimpan.",
        );
    }

    /**
     * Index unique partial `uq_konteks_utama` hanya mengizinkan satu baris
     * bernilai true, jadi yang lama harus dilepas dalam transaksi yang sama.
     */
    private function lepasUtamaLama(bool $jadiUtama, ?int $kecuali = null): void
    {
        if (! $jadiUtama) {
            return;
        }

        KonteksPantauan::query()
            ->where('utama', true)
            ->when($kecuali, fn ($q, $id) => $q->where('id', '!=', $id))
            ->update(['utama' => false]);
    }
}
