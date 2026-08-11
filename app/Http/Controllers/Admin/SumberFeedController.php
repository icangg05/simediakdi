<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanSumberFeedRequest;
use App\Models\Media;
use App\Models\SumberFeed;
use Illuminate\Http\RedirectResponse;

/**
 * Sumber feed selalu bersarang di bawah satu media.
 *
 * Daftar dan formulirnya yang berdiri sendiri sudah dihapus. Sumber feed tidak
 * pernah punya arti tanpa medianya, dan halaman terpisah memaksa admin memilih
 * media dari dropdown hanya untuk mengubah satu alamat yang sedang dilihatnya
 * di halaman lain. Ketiga aksi di sini kembali ke halaman detail medianya.
 */
class SumberFeedController extends Controller
{
    public function store(SimpanSumberFeedRequest $request, Media $media): RedirectResponse
    {
        // `media_id` datang dari rute, bukan dari kiriman form. Membiarkannya
        // di badan permintaan berarti admin bisa memindahkan sumber ke media
        // mana pun hanya dengan mengubah satu field tersembunyi.
        $sumber = $media->sumberFeed()->create($request->validated());

        return back()->with('sukses', "Sumber {$sumber->nama} ditambahkan.");
    }

    public function update(SimpanSumberFeedRequest $request, Media $media, SumberFeed $sumberFeed): RedirectResponse
    {
        abort_unless($sumberFeed->media_id === $media->id, 404);

        $sumberFeed->update($request->validated());

        // Admin yang mengaktifkan ulang sumber berarti sudah memperbaiki
        // penyebabnya; hitungan gagal dimulai dari nol lagi.
        if ($sumberFeed->wasChanged('aktif') && $sumberFeed->aktif) {
            $sumberFeed->update(['gagal_berturut' => 0, 'pesan_error_terakhir' => null]);
        }

        return back()->with('sukses', "Sumber {$sumberFeed->nama} diperbarui.");
    }

    public function destroy(Media $media, SumberFeed $sumberFeed): RedirectResponse
    {
        abort_unless($sumberFeed->media_id === $media->id, 404);

        $nama = $sumberFeed->nama;
        $sumberFeed->delete();

        return back()->with('sukses', "Sumber {$nama} dihapus.");
    }
}
