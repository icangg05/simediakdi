<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArtikelPortalResource;
use App\Models\Artikel;
use App\Support\Waktu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Beranda portal media.
 *
 * Seluruh kueri di sini bersandar pada global scope MilikMedia, bukan pada
 * `where('media_id', ...)` yang ditulis tangan. Bedanya penting: kalau
 * penyaringan ditulis per kueri, satu kueri yang lupa menuliskannya membocorkan
 * data media lain, dan tidak ada yang menyadarinya sampai ada yang protes.
 *
 * Yang ditampilkan hanya berita yang sudah dinilai relevan dan berlabel,
 * populasi yang sama dengan halaman "Berita saya" dan dengan angka di panel
 * eksekutif. Artikel hasil crawl yang belum selesai dinilai tidak dihitung di
 * mana pun, jadi tidak ada dua halaman yang bisa menyebut angka berbeda untuk
 * hal yang sama.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $sejak = Waktu::awalHariIni()->subDays(30);

        return Inertia::render('portal/Dashboard', [
            // Nama media pengguna, dipakai sebagai judul halaman. Portal ini
            // hanya pernah menampilkan satu media, dan menyebut namanya di kop
            // adalah cara termurah membuat pengguna yakin ia melihat datanya
            // sendiri, bukan rekap seluruh media partner.
            'media' => $request->user()?->media?->nama,
            // Rentang yang dipakai dua dari tiga angka di bawah, dikirim
            // sebagai tanggal, bukan kalimat. Halaman yang menyebut "30 hari"
            // tanpa menyebut 30 hari yang mana memaksa pengguna menghitung
            // sendiri, dan itu persis jenis angka yang berhenti dipercaya.
            'periode' => [
                'dari' => $sejak->toDateString(),
                'sampai' => Waktu::awalHariIni()->toDateString(),
            ],
            'kpi' => [
                'berita_30_hari' => Artikel::query()
                    ->relevanBerlabel()
                    ->where('diambil_at', '>=', $sejak)
                    ->count(),
                // Dibatasi jendela yang sama dengan angka di sebelahnya.
                // Sebelumnya angka ini menghitung sepanjang masa sementara
                // tetangganya menghitung 30 hari, jadi keduanya berdiri
                // bersebelahan tanpa bisa dibandingkan.
                'ditambahkan_sendiri' => Artikel::query()
                    ->whereNotNull('dilaporkan_oleh')
                    ->where('diambil_at', '>=', $sejak)
                    ->count(),
                // Yang satu ini sengaja tidak dibatasi jendela. Ia tumpukan
                // pekerjaan yang belum selesai, dan laporan dari 40 hari lalu
                // yang masih menggantung justru yang paling perlu terlihat.
                'sedang_diproses' => Artikel::query()
                    ->whereNotNull('dilaporkan_oleh')
                    ->whereDoesntHave('analisisSentimen', fn (Builder $q) => $q
                        ->where('relevan', true)
                        ->whereNotNull('label_efektif'))
                    ->count(),
            ],
            // Dua aturan berbeda untuk dua asal yang berbeda, dan bedanya
            // disengaja.
            //
            // Berita temuan crawler tetap harus lolos `relevanBerlabel()`.
            // Crawler menyimpan seluruh isi feed lebih dulu, termasuk berita
            // yang kemudian dinilai di luar pantauan Pemkot, dan menampilkan
            // semuanya di beranda berarti membanjiri media dengan baris yang
            // tidak pernah mereka minta dan tidak bisa mereka apa-apakan.
            //
            // Berita yang ditambahkan media sendiri selalu tampil, apa pun
            // tahapnya. Merekalah yang mengetiknya, jadi merekalah yang berhak
            // tahu apa yang terjadi padanya, termasuk ketika jawabannya "di
            // luar pantauan" atau "gagal dibaca". Menyembunyikannya justru
            // membuat media mengirimkan tautan yang sama berulang kali.
            //
            // Tiap baris membawa lencana tahapnya, dan hanya yang berlencana
            // "Tampil" yang ikut terhitung di "Berita saya".
            'beritaTerbaru' => ArtikelPortalResource::collection(
                Artikel::query()
                    ->where(fn (Builder $q) => $q->relevanBerlabel()->orWhereNotNull('dilaporkan_oleh'))
                    ->with(['media:id,nama', 'analisisSentimen:id,artikel_id,relevan,label_efektif'])
                    ->latest('diambil_at')
                    ->limit(6)
                    ->get()
            )->resolve(),
        ]);
    }
}
