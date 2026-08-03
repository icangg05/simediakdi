<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanAturanAlertRequest;
use App\Models\AturanAlert;
use App\Models\KonteksPantauan;
use App\Models\RiwayatAlert;
use App\Services\Alert\PemeriksaAturan;
use App\Services\Alert\PengirimTelegram;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AturanAlertController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/alert/Index', [
            'aturan' => AturanAlert::query()
                ->with('konteks:id,nama')
                ->withCount('riwayat')
                ->orderBy('nama')
                ->get()
                ->map(fn (AturanAlert $a) => [
                    ...$a->only(['id', 'nama', 'jenis', 'kanal', 'aktif', 'jendela_jam', 'jeda_minimal_jam', 'riwayat_count']),
                    'konteks' => $a->konteks?->nama,
                    'dipicu_terakhir_at' => $a->dipicu_terakhir_at,
                ])
                ->all(),
            'riwayat' => RiwayatAlert::query()
                ->with('aturan:id,nama')
                ->orderByDesc('dipicu_at')
                ->limit(50)
                ->get()
                ->map(fn (RiwayatAlert $r) => [
                    ...$r->only(['id', 'ringkasan', 'status_kirim', 'pesan_error']),
                    'aturan' => $r->aturan?->nama,
                    'dipicu_at' => $r->dipicu_at,
                    'dibaca_at' => $r->dibaca_at,
                ])
                ->all(),
            // Telegram belum terkonfigurasi adalah kegagalan diam: aturan
            // tersimpan rapi, terpicu benar, dan tidak seorang pun menerima
            // apa pun. Ditampilkan di halaman, bukan hanya di log.
            'telegramSiap' => config('alert.telegram.token') !== '' && config('alert.telegram.chat_id') !== '',
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/alert/Form', [
            'aturan' => null,
            'daftarKonteks' => self::daftarKonteks(),
        ]);
    }

    public function store(SimpanAturanAlertRequest $request): RedirectResponse
    {
        $aturan = AturanAlert::create($this->data($request));

        return to_route('admin.alert.index')->with('sukses', "Aturan {$aturan->nama} ditambahkan.");
    }

    public function edit(AturanAlert $alert): Response
    {
        return Inertia::render('admin/alert/Form', [
            'aturan' => $alert,
            'daftarKonteks' => self::daftarKonteks(),
        ]);
    }

    public function update(SimpanAturanAlertRequest $request, AturanAlert $alert): RedirectResponse
    {
        $alert->update($this->data($request));

        return to_route('admin.alert.index')->with('sukses', "Aturan {$alert->nama} diperbarui.");
    }

    public function destroy(AturanAlert $alert): RedirectResponse
    {
        $alert->delete();

        return to_route('admin.alert.index')->with('sukses', "Aturan {$alert->nama} dihapus.");
    }

    /**
     * Uji satu aturan tanpa menunggu scheduler dan tanpa menulis riwayat.
     *
     * Ada karena ambang alert hampir tidak pernah benar pada percobaan pertama.
     * Tanpa tombol ini, menyetel ambang berarti menunggu 15 menit per percobaan
     * sambil mengirimi grup Diskominfo pesan uji.
     */
    public function uji(AturanAlert $alert, PemeriksaAturan $pemeriksa): RedirectResponse
    {
        $hasil = $pemeriksa->nilai($alert);

        return back()->with('sukses', $hasil === null
            ? "Aturan {$alert->nama} tidak terpicu dengan data saat ini."
            : "Terpicu: {$hasil['ringkasan']} (uji coba, tidak dikirim dan tidak masuk riwayat).");
    }

    /** Kirim satu pesan uji ke Telegram, untuk memastikan chat ID benar. */
    public function ujiTelegram(PengirimTelegram $telegram): RedirectResponse
    {
        $hasil = $telegram->kirim('Uji koneksi SIMEDIA Kendari. Kalau pesan ini terbaca, alert akan sampai.');

        return back()->with(
            $hasil['terkirim'] ? 'sukses' : 'galat',
            $hasil['terkirim']
                ? 'Pesan uji terkirim ke grup Telegram.'
                : "Telegram menolak: {$hasil['error']}",
        );
    }

    /** @return array<string, mixed> */
    private function data(SimpanAturanAlertRequest $request): array
    {
        $data = $request->validated();

        $data['penerima'] = $data['penerima'] ?? [];
        $data['kondisi'] = $data['kondisi'] ?? [];
        $data['aktif'] = $data['aktif'] ?? true;

        // Jenis selain lonjakan tidak dinilai per konteks. Menyimpan konteks
        // di sana hanya menyesatkan orang yang membacanya nanti.
        if ($data['jenis'] !== 'lonjakan_negatif') {
            $data['konteks_pantauan_id'] = null;
        }

        return $data;
    }

    /** @return list<array{nilai: string, label: string}> */
    private static function daftarKonteks(): array
    {
        return KonteksPantauan::query()->where('aktif', true)->orderBy('nama')->get(['id', 'nama'])
            ->map(fn (KonteksPantauan $k) => ['nilai' => (string) $k->id, 'label' => $k->nama])->all();
    }
}
