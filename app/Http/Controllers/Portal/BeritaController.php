<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArtikelPortalResource;
use App\Models\Artikel;
use App\Support\KueriTabel;
use App\Support\Periode;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Berita saya": berita media ini yang masuk pantauan.
 *
 * Populasinya dua aturan, bukan satu. Berita temuan crawler harus lolos
 * `relevanBerlabel()`, populasi yang sama dengan panel eksekutif, sehingga
 * media melihat berita yang benar-benar dihitung sistem dan bukan seluruh isi
 * hasil crawl. Berita yang ditambahkan media sendiri selalu masuk, apa pun
 * tahapnya, karena merekalah yang mengirimnya dan berhak tahu hasilnya.
 *
 * Konsekuensinya jumlah baris di sini bisa melebihi KPI "Berita terpantau" di
 * beranda, yang hanya menghitung tahap tampil. Itu bukan selisih yang
 * disembunyikan: kolom Tahap menyebut posisi tiap baris, dan filter Asal
 * memisahkan kedua populasinya.
 *
 * Dibentuk lewat ArtikelPortalResource, bukan `$artikel->only([...])` yang
 * ditulis di sini. Satu tempat yang memutuskan field apa yang boleh dilihat
 * media berarti satu tempat yang perlu diperiksa saat menambah kolom baru.
 */
class BeritaController extends Controller
{
    /**
     * Rentang bawaan saat media belum memilih apa pun.
     *
     * Tiga puluh hari, sama dengan KPI di beranda portal. Kalau bawaannya
     * berbeda, media membaca satu angka di beranda lalu menghitung baris di
     * tabel ini dan mendapati keduanya tidak cocok.
     */
    private const HARI_BAWAAN = 30;

    public function __invoke(Request $request): Response
    {
        // Tanggal kalender WITA, bukan instan UTC. `Waktu::awalHariIni()`
        // mengembalikan pukul 00.00 WITA sebagai instan UTC, dan
        // `toDateString()` atasnya menjawab tanggal kemarin karena Kendari
        // berada di UTC+8. Periode menyimpan tanggal, jadi ia harus dibangun
        // dari tanggal.
        $hariIni = CarbonImmutable::parse(Waktu::tanggalWita(now()));

        $periode = Periode::dariRequestDenganBawaan(
            $request,
            $hariIni->subDays(self::HARI_BAWAAN - 1),
            $hariIni,
        );

        $asal = $this->asalTerpilih($request);

        // Disaring menurut tanggal terbit, bukan tanggal unduh. Penarikan arsip
        // memasukkan berita lama pada satu hari yang sama, dan menyaringnya
        // dengan tanggal unduh membuat berita bulan lalu menghilang dari rentang
        // yang seharusnya memuatnya.
        $artikel = KueriTabel::untuk(
            Artikel::query()
                // Aturan yang sama dengan beranda portal, dan sengaja bukan
                // `relevanBerlabel()` saja.
                //
                // Dengan saringan lama, filter Asal di halaman ini menjanjikan
                // sesuatu yang tidak bisa ditepatinya: memilih "Anda tambahkan"
                // menyaring populasi yang sudah lebih dulu membuang seluruh
                // kiriman yang belum selesai dinilai, jadi media yang baru
                // mengirim dua berita menekan filternya dan mendapat tabel
                // kosong.
                //
                // Berita temuan crawler tetap harus lolos penilaian. Yang
                // ditambahkan media sendiri selalu masuk, dan kolom Tahap yang
                // menyebutkan sampai mana perjalanannya.
                ->where(fn (Builder $q) => $q->relevanBerlabel()->orWhereNotNull('dilaporkan_oleh'))
                ->terbitAntara($periode->mulaiUtc(), $periode->akhirUtc())
                // Nama nilainya sengaja sama dengan filter asal di panel admin,
                // yaitu `crawler` dan `portal`. Dua panel yang menyaring hal
                // yang sama dengan dua kosakata berbeda membuat tautan hasil
                // filter tidak bisa dipindahkan antar panel saat admin dan media
                // membicarakan baris yang sama.
                ->when($asal === 'portal', fn (Builder $q) => $q->whereNotNull('dilaporkan_oleh'))
                ->when($asal === 'crawler', fn (Builder $q) => $q->whereNull('dilaporkan_oleh'))
                ->with(['media:id,nama', 'analisisSentimen:id,artikel_id,relevan,label_efektif']),
            $request,
        )
            ->cari(['judul'])
            ->urut(['judul', 'diambil_at', 'dipublikasikan_at'], 'diambil_at', 'desc')
            ->halaman();

        return Inertia::render('portal/Berita', [
            'artikel' => $artikel->through(fn (Artikel $a) => (new ArtikelPortalResource($a))->resolve()),
            ...$periode->untukInertia(),
        ]);
    }

    /**
     * Asal berita yang sedang disaring, atau null kalau tidak menyaring apa pun.
     *
     * Nilai apa pun di luar daftar putih dibaca sebagai "tanpa saringan", bukan
     * ditolak dengan galat. Termasuk `crawler,portal` yang dikirim filter
     * bertanda centang saat kedua kotaknya dicentang sekaligus, dan itu memang
     * berarti seluruh baris.
     */
    private function asalTerpilih(Request $request): ?string
    {
        $asal = $request->string('asal')->toString();

        return \in_array($asal, ['crawler', 'portal'], true) ? $asal : null;
    }
}
