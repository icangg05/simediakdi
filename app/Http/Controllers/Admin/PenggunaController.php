<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PeranPengguna;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanPenggunaRequest;
use App\Models\Media;
use App\Models\User;
use App\Support\KueriTabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F-46: akun dinonaktifkan, bukan dihapus.
 *
 * Registrasi mandiri tidak dibuka, jadi halaman inilah satu-satunya jalan akun
 * dibuat, termasuk 30 akun media di sprint 5. Seluruh perubahan tercatat di
 * audit log lewat LogsActivity pada model User.
 */
class PenggunaController extends Controller
{
    public function index(Request $request): Response
    {
        $pengguna = KueriTabel::untuk(User::query()->with('media:id,nama'), $request)
            ->cari(['name', 'email', 'jabatan'])
            ->saring(['peran' => 'peran', 'aktif' => 'aktif', 'media' => 'media_id'])
            ->urut(['name', 'email', 'peran', 'login_terakhir_at'], 'name')
            ->halaman();

        return Inertia::render('admin/pengguna/Index', [
            'pengguna' => $pengguna->through(fn (User $u) => [
                ...$u->only(['id', 'name', 'email', 'jabatan', 'aktif']),
                'peran' => $u->peran->value,
                'peran_label' => $u->peran->label(),
                'media' => $u->media?->only(['id', 'nama']),
                'login_terakhir_at' => $u->login_terakhir_at,
            ]),
            'opsi' => [
                'peran' => array_map(
                    fn (PeranPengguna $p) => ['nilai' => $p->value, 'label' => $p->label()],
                    PeranPengguna::cases(),
                ),
                'aktif' => [
                    ['nilai' => 'true', 'label' => 'Aktif'],
                    ['nilai' => 'false', 'label' => 'Nonaktif'],
                ],
                'media' => self::daftarMedia(),
            ],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/pengguna/Form', [
            'pengguna' => null,
            'daftarMedia' => self::daftarMedia(),
        ]);
    }

    public function store(SimpanPenggunaRequest $request): RedirectResponse
    {
        $pengguna = User::create($request->validated());

        return to_route('admin.pengguna.index')
            ->with('sukses', "Akun {$pengguna->name} dibuat sebagai {$pengguna->peran->label()}.");
    }

    public function edit(User $pengguna): Response
    {
        return Inertia::render('admin/pengguna/Form', [
            'pengguna' => [
                ...$pengguna->only(['id', 'name', 'email', 'jabatan', 'telepon', 'aktif', 'media_id']),
                'peran' => $pengguna->peran->value,
            ],
            'daftarMedia' => self::daftarMedia(),
        ]);
    }

    public function update(SimpanPenggunaRequest $request, User $pengguna): RedirectResponse
    {
        $data = $request->validated();

        if ($galat = $this->alasanTidakBolehDiubah($request, $pengguna, $data)) {
            return back()->with('galat', $galat);
        }

        // Kata sandi kosong berarti tidak diubah, bukan dikosongkan.
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $pengguna->update($data);

        return to_route('admin.pengguna.index')->with('sukses', "Akun {$pengguna->name} diperbarui.");
    }

    /**
     * Menonaktifkan, bukan menghapus (F-46). Jejak audit akun tetap bisa
     * ditelusuri, dan artikel maupun koreksi label yang pernah dibuatnya tidak
     * kehilangan pemilik.
     */
    public function destroy(Request $request, User $pengguna): RedirectResponse
    {
        if ($pengguna->id === $request->user()->id) {
            return back()->with('galat', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }

        $pengguna->update(['aktif' => false]);

        return to_route('admin.pengguna.index')->with('sukses', "Akun {$pengguna->name} dinonaktifkan.");
    }

    /**
     * Mencegah admin mengunci dirinya sendiri keluar.
     *
     * Dua jalur yang bisa melakukannya, keduanya lewat form ubah: menonaktifkan
     * akun sendiri, dan menurunkan peran sendiri dari superadmin. Kalau itu
     * terjadi pada superadmin terakhir, tidak ada jalan kembali lewat aplikasi,
     * registrasi mandiri tidak dibuka, jadi pemulihannya harus lewat database.
     *
     * Penjagaan "superadmin aktif terakhir" sengaja tidak ditambahkan: ia tidak
     * pernah bisa tercapai. Pengguna nonaktif ditolak middleware peran, dan
     * satu-satunya superadmin aktif yang menonaktifkan superadmin aktif sudah
     * pasti sedang menonaktifkan dirinya sendiri, tertangkap penjagaan di sini.
     */
    private function alasanTidakBolehDiubah(Request $request, User $pengguna, array $data): ?string
    {
        if ($pengguna->id !== $request->user()->id) {
            return null;
        }

        if (! $data['aktif']) {
            return 'Anda tidak dapat menonaktifkan akun Anda sendiri.';
        }

        if ($pengguna->peran === PeranPengguna::Superadmin && $data['peran'] !== PeranPengguna::Superadmin->value) {
            return 'Anda tidak dapat menurunkan peran akun Anda sendiri. Minta superadmin lain yang melakukannya.';
        }

        return null;
    }

    /** @return list<array{nilai: string, label: string}> */
    private static function daftarMedia(): array
    {
        return Media::query()->where('aktif', true)->orderBy('nama')->get(['id', 'nama'])
            ->map(fn (Media $m) => ['nilai' => (string) $m->id, 'label' => $m->nama])->all();
    }
}
