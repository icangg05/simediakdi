<?php

namespace App\Http\Controllers\Eksekutif;

use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Support\UrlEksternal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Detail berita untuk pimpinan.
 *
 * Halaman menampilkan isi hasil ekstraksi dan dasar putusan yang sama dengan
 * layar detail admin, tetapi tetap baca-saja: pimpinan dapat memeriksa alasan
 * serta kutipan bukti tanpa memperoleh kendali koreksi atau klasifikasi ulang.
 */
class ArtikelController extends Controller
{
    public function __invoke(Request $request, Artikel $artikel): Response
    {
        // Route model binding saja belum cukup: id artikel yang ditebak tidak
        // boleh membuka artikel belum selesai atau yang dinyatakan tidak
        // relevan. Populasinya harus sama dengan seluruh daftar eksekutif.
        $artikel = Artikel::query()
            ->relevanBerlabel()
            ->with([
                'media:id,nama,domain,partner',
                'analisisSentimen' => fn ($q) => $q
                    ->where('relevan', true)
                    ->whereNotNull('label_efektif'),
            ])
            ->findOrFail($artikel->id);

        $analisis = $artikel->analisisSentimen->first();

        return Inertia::render('eksekutif/DetailArtikel', [
            'artikel' => [
                'id' => $artikel->id,
                'judul' => $artikel->judul,
                'url' => UrlEksternal::http($artikel->url),
                'isi' => $artikel->isi,
                'jumlah_kata' => $artikel->jumlah_kata,
                // Ringkasan lengkap dibutuhkan oleh kontrol "Selengkapnya".
                // HTML dari feed tetap dibuang agar markup sumber tidak masuk
                // ke Inertia.
                'ringkasan' => $this->bersihkan($artikel->ringkasan),
                'penulis' => $artikel->penulis,
                'dipublikasikan_at' => $artikel->dipublikasikan_at,
                'diambil_at' => $artikel->diambil_at,
                'media' => $artikel->media === null ? null : [
                    'nama' => $artikel->media->nama,
                    'domain' => $artikel->media->domain,
                    'partner' => (bool) $artikel->media->partner,
                ],
                'analisis' => [
                    'relevan' => (bool) $analisis?->relevan,
                    'label' => $analisis?->label_efektif?->value,
                    'perlu_review' => (bool) $analisis?->perlu_review,
                    'alasan' => $this->bersihkan($analisis?->reason_summary),
                    'bukti' => collect($analisis?->evidence ?? [])
                        ->filter(fn (mixed $kutipan): bool => is_string($kutipan))
                        ->map(fn (string $kutipan): ?string => $this->bersihkan($kutipan))
                        ->filter()
                        ->values()
                        ->all(),
                    'provider' => $analisis?->provider,
                    'model_versi' => $analisis?->model_versi,
                    'dianalisis_at' => $analisis?->dianalisis_at,
                ],
            ],
            'kembali' => $this->tujuanKembali($request),
        ]);
    }

    /** Buang markup sumber tanpa memangkas isi teksnya. */
    private function bersihkan(?string $teks): ?string
    {
        if ($teks === null) {
            return null;
        }

        $bersih = Str::of($teks)->stripTags()->squish()->toString();

        return $bersih === '' ? null : $bersih;
    }

    /**
     * Hanya alamat internal panel eksekutif yang boleh dipakai sebagai tujuan
     * kembali. Nilai lain jatuh ke arsip, sehingga parameter tidak dapat
     * dijadikan pengalihan ke situs luar atau putaran antarhalaman detail.
     */
    private function tujuanKembali(Request $request): string
    {
        $kembali = $request->string('kembali')->trim()->toString();

        if (
            ! Str::startsWith($kembali, '/eksekutif')
            || Str::startsWith($kembali, '//')
            || Str::startsWith($kembali, '/eksekutif/artikel/')
        ) {
            return route('eksekutif.berita', absolute: false);
        }

        return $kembali;
    }
}
