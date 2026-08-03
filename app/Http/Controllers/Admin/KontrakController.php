<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanKontrakRequest;
use App\Models\Kontrak;
use App\Models\Media;
use App\Services\Kontrak\PencocokPemuatan;
use App\Support\KueriTabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KontrakController extends Controller
{
    public function index(Request $request, PencocokPemuatan $pencocok): Response
    {
        $kontrak = KueriTabel::untuk(Kontrak::query()->with('media:id,nama'), $request)
            ->cari(['judul', 'nomor'])
            ->saring(['status' => 'status', 'jenis' => 'jenis', 'media' => 'media_id'])
            ->urut(['judul', 'tanggal_akhir', 'tanggal_mulai', 'nilai'], 'tanggal_akhir', 'desc')
            ->halaman();

        return Inertia::render('admin/kontrak/Index', [
            'kontrak' => $kontrak->through(fn (Kontrak $k) => [
                ...$k->only(['id', 'nomor', 'judul', 'jenis', 'status', 'nilai', 'target_pemuatan']),
                'tanggal_mulai' => $k->tanggal_mulai,
                'tanggal_akhir' => $k->tanggal_akhir,
                'media' => $k->media?->only(['id', 'nama']),
                'progres' => $pencocok->progres($k),
            ]),
            'opsi' => self::opsi(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/kontrak/Form', [
            'kontrak' => null,
            'daftarMedia' => self::daftarMedia(),
        ]);
    }

    public function store(SimpanKontrakRequest $request, PencocokPemuatan $pencocok): RedirectResponse
    {
        $kontrak = Kontrak::create($this->data($request));

        $baru = $pencocok->cocokkan($kontrak);

        return to_route('admin.kontrak.show', $kontrak)->with(
            'sukses',
            "Kontrak {$kontrak->judul} ditambahkan."
            .($baru > 0 ? " {$baru} artikel yang sudah ter-crawl langsung tercatat sebagai pemuatan." : ''),
        );
    }

    public function show(Kontrak $kontrak, PencocokPemuatan $pencocok): Response
    {
        $kontrak->load('media:id,nama');

        return Inertia::render('admin/kontrak/Detail', [
            'kontrak' => [
                ...$kontrak->toArray(),
                'media' => $kontrak->media?->only(['id', 'nama']),
            ],
            'progres' => $pencocok->progres($kontrak),
            'pemuatan' => $kontrak->pemuatan()
                ->orderByDesc('tanggal_muat')
                ->limit(200)
                ->get(['id', 'url', 'judul', 'tanggal_muat', 'sumber_catatan', 'status_verifikasi'])
                ->all(),
        ]);
    }

    public function edit(Kontrak $kontrak): Response
    {
        return Inertia::render('admin/kontrak/Form', [
            'kontrak' => $kontrak,
            'daftarMedia' => self::daftarMedia(),
        ]);
    }

    public function update(SimpanKontrakRequest $request, Kontrak $kontrak, PencocokPemuatan $pencocok): RedirectResponse
    {
        $kontrak->update($this->data($request, $kontrak));

        $baru = $pencocok->cocokkan($kontrak);

        return to_route('admin.kontrak.show', $kontrak)->with(
            'sukses',
            "Kontrak {$kontrak->judul} diperbarui."
            .($baru > 0 ? " {$baru} pemuatan baru tercatat." : ''),
        );
    }

    /** Soft delete: pemuatan yang sudah tercatat tetap bisa ditelusuri. */
    public function destroy(Kontrak $kontrak): RedirectResponse
    {
        $kontrak->delete();

        return to_route('admin.kontrak.index')->with('sukses', "Kontrak {$kontrak->judul} dihapus.");
    }

    /** Cocokkan ulang secara manual, tanpa mengubah data kontraknya. */
    public function cocokkan(Kontrak $kontrak, PencocokPemuatan $pencocok): RedirectResponse
    {
        $baru = $pencocok->cocokkan($kontrak);

        return back()->with('sukses', $baru > 0
            ? "{$baru} pemuatan baru tercatat."
            : 'Tidak ada artikel baru yang cocok dengan periode kontrak ini.');
    }

    /** @return array<string, mixed> */
    private function data(SimpanKontrakRequest $request, ?Kontrak $kontrak = null): array
    {
        $data = $request->safe()->except('berkas');

        if ($request->hasFile('berkas')) {
            // Di luar public/: dokumen kontrak memuat nilai rupiah dan tidak
            // boleh terbuka dengan menebak URL (dokumen 06 bagian 7).
            $data['berkas_path'] = $request->file('berkas')->store('kontrak', 'local');
        }

        return $data;
    }

    /** @return array<string, list<array{nilai: string, label: string}>> */
    private static function opsi(): array
    {
        return [
            'status' => [
                ['nilai' => 'draft', 'label' => 'Draft'],
                ['nilai' => 'aktif', 'label' => 'Aktif'],
                ['nilai' => 'selesai', 'label' => 'Selesai'],
                ['nilai' => 'batal', 'label' => 'Batal'],
            ],
            'jenis' => [
                ['nilai' => 'advertorial', 'label' => 'Advertorial'],
                ['nilai' => 'publikasi', 'label' => 'Publikasi'],
                ['nilai' => 'banner', 'label' => 'Banner'],
                ['nilai' => 'lain', 'label' => 'Lain'],
            ],
            'media' => self::daftarMedia(),
        ];
    }

    /** @return list<array{nilai: string, label: string}> */
    private static function daftarMedia(): array
    {
        return Media::query()->where('aktif', true)->orderBy('nama')->get(['id', 'nama'])
            ->map(fn (Media $m) => ['nilai' => (string) $m->id, 'label' => $m->nama])->all();
    }
}
