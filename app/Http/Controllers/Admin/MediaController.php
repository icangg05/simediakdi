<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TierMedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimpanMediaRequest;
use App\Jobs\TemukanFeedMedia;
use App\Models\LogCrawl;
use App\Models\Media;
use App\Models\SumberFeed;
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
        $media = KueriTabel::untuk(
            Media::query()
                ->withCount([
                    'sumberFeed',
                    'sumberFeed as sumber_feed_aktif_count' => fn ($q) => $q->where('aktif', true),
                ]),
            $request,
        )
            ->cari(['nama', 'domain', 'kota'])
            ->saring(['tier' => 'tier', 'partner' => 'partner', 'aktif' => 'aktif'])
            ->urut(['nama', 'tier', 'domain', 'created_at'], 'nama')
            ->halaman();

        return Inertia::render('admin/media/Index', [
            'media' => $media,
            'opsi' => self::opsiFilter(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/media/Form', [
            'media' => null,
            'slugTerpakai' => $this->slugTerpakai(),
        ]);
    }

    public function store(SimpanMediaRequest $request): RedirectResponse
    {
        $media = Media::create($request->validated());

        TemukanFeedMedia::dispatch($media->id);

        return to_route('admin.media.show', $media)
            ->with('sukses', "Media {$media->nama} ditambahkan.")
            ->with('catatan', 'Alamat RSS-nya sedang dicari otomatis dari domain. Muat ulang halaman ini sebentar lagi.');
    }

    /**
     * Halaman detail: identitas, sumber feed, dan riwayat pengambilannya.
     *
     * Menggantikan halaman Sumber Feed yang berdiri sendiri. Sumber feed tidak
     * pernah berarti apa pun tanpa medianya, dan daftar global memaksa admin
     * mengingat sumber mana milik siapa hanya untuk mengubah satu alamat.
     */
    public function show(Media $media): Response
    {
        $media->loadCount('artikel');

        return Inertia::render('admin/media/Detail', [
            'media' => $media,
            'sumberFeed' => $media->sumberFeed()
                ->orderBy('nama')
                ->get()
                ->map(fn (SumberFeed $s) => [
                    ...$s->only([
                        'id', 'nama', 'tipe', 'url', 'selector', 'kata_kunci',
                        'interval_menit', 'aktif', 'gagal_berturut', 'pesan_error_terakhir',
                    ]),
                    'berhasil_terakhir_at' => $s->berhasil_terakhir_at?->toIso8601String(),
                    'dijalankan_terakhir_at' => $s->dijalankan_terakhir_at?->toIso8601String(),
                ]),
            // Riwayat lintas seluruh sumber milik media ini. Halaman Log crawl
            // tetap ada untuk penelusuran menyeluruh, yang di sini hanya cukup
            // untuk menjawab "pengambilan terakhirnya berhasil atau tidak".
            'riwayat' => LogCrawl::query()
                ->whereIn('sumber_feed_id', $media->sumberFeed()->pluck('id'))
                ->with('sumberFeed:id,nama')
                ->latest('dimulai_at')
                ->limit(10)
                ->get()
                ->map(fn (LogCrawl $l) => [
                    'id' => $l->id,
                    'sumber' => $l->sumberFeed?->nama,
                    'status' => $l->status,
                    'jumlah_baru' => $l->jumlah_baru,
                    'jumlah_ditemukan' => $l->jumlah_ditemukan,
                    'pesan' => $l->pesan,
                    'dimulai_at' => $l->dimulai_at?->toIso8601String(),
                ]),
            'maksGagal' => (int) config('crawler.maks_gagal_berturut'),
        ]);
    }

    public function edit(Media $media): Response
    {
        return Inertia::render('admin/media/Form', [
            'media' => $media,
            'slugTerpakai' => $this->slugTerpakai($media),
        ]);
    }

    public function update(SimpanMediaRequest $request, Media $media): RedirectResponse
    {
        $media->update($request->validated());

        // Domain berganti berarti alamat feed lama hampir pasti mati. Pencarian
        // diulang, tetapi hanya kalau medianya memang belum punya sumber feed:
        // alamat yang sudah diisi tangan tidak boleh ditimpa hasil tebakan.
        if ($media->wasChanged(['domain', 'url_website'])) {
            $media->forceFill(['feed_dicari_at' => null])->save();
            TemukanFeedMedia::dispatch($media->id);
        }

        return to_route('admin.media.show', $media)
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
        if (! $media->aktif) {
            return back()->with('galat', "{$media->nama} sedang nonaktif. Aktifkan dulu sebelum menariknya.");
        }

        $jumlah = $media->sumberFeed()->where('aktif', true)->count();

        if ($jumlah === 0) {
            return back()->with('galat', "{$media->nama} belum punya sumber feed aktif.");
        }

        Artisan::queue('crawl:feeds', ['--media' => $media->id, '--paksa' => true]);

        return back()->with('sukses', "Crawl {$media->nama} dijalankan di latar belakang, {$jumlah} sumber.")
            ->with('catatan', 'Hasilnya muncul di halaman Log crawl beberapa saat lagi.');
    }

    /**
     * Saklar induk pengambilan berita.
     *
     * Dipisahkan dari `destroy` karena keduanya memang beda maksud, dan dulu
     * disatukan. Tombol berlabel "Nonaktifkan" memanggil soft delete, sehingga
     * medianya lenyap dari daftar alih-alih tampil sebagai baris nonaktif, dan
     * satu-satunya cara menghidupkannya lagi adalah lewat basis data.
     *
     * Sejak seluruh media dicrawl secara bawaan, saklar ini yang menjadi satu
     * -satunya cara menghentikan pengambilan tanpa membuang datanya.
     */
    public function aktif(Media $media): RedirectResponse
    {
        $media->update(['aktif' => ! $media->aktif]);

        return back()->with(
            'sukses',
            $media->aktif
                ? "{$media->nama} diaktifkan. Beritanya mulai ditarik lagi pada jadwal berikutnya."
                : "{$media->nama} dinonaktifkan. Tidak ada lagi berita yang ditarik dari media ini.",
        );
    }

    public function destroy(Media $media): RedirectResponse
    {
        // Soft delete: artikel yang sudah terkumpul tetap menunjuk media ini.
        $media->delete();

        return to_route('admin.media.index')
            ->with('sukses', "Media {$media->nama} dihapus. Artikel yang sudah terkumpul tetap tersimpan.");
    }

    /** @return array<string, list<array{nilai: string, label: string}>> */
    private static function opsiFilter(): array
    {
        return [
            'tier' => array_map(
                fn (TierMedia $t) => ['nilai' => $t->value, 'label' => ucfirst($t->value)],
                TierMedia::cases(),
            ),
            'partner' => [
                ['nilai' => 'true', 'label' => 'Bekerja sama'],
                ['nilai' => 'false', 'label' => 'Tidak bekerja sama'],
            ],
            'aktif' => [
                ['nilai' => 'true', 'label' => 'Aktif'],
                ['nilai' => 'false', 'label' => 'Nonaktif'],
            ],
        ];
    }

    /**
     * Slug dipakai form untuk menampilkan pratinjau unik tanpa menunggu submit.
     * Baris soft-delete tetap dikirim karena indeks unik database juga tetap
     * menyimpannya.
     *
     * @return list<string>
     */
    private function slugTerpakai(?Media $kecuali = null): array
    {
        return Media::withoutGlobalScopes()
            ->withTrashed()
            ->when($kecuali, fn ($kueri) => $kueri->where('id', '!=', $kecuali->id))
            ->orderBy('slug')
            ->pluck('slug')
            ->all();
    }
}
