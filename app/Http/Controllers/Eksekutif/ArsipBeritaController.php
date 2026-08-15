<?php

namespace App\Http\Controllers\Eksekutif;

use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Models\Media;
use App\Services\Agregasi\RingkasanEksekutif;
use App\Support\KueriTabel;
use App\Support\Periode;
use App\Support\UrlEksternal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ArsipBeritaController extends Controller
{
    public function __invoke(Request $request, RingkasanEksekutif $ringkasan): Response
    {
        $periode = Periode::dariRequest($request, $ringkasan);

        // Arsip yang dibuka pimpinan hanya berisi berita yang relevan dan sudah
        // berlabel, populasi yang sama dengan angka di halaman ringkasan.
        // Kalau tidak, jumlah baris di sini tidak akan pernah cocok dengan KPI.
        $kueri = Artikel::query()
            ->relevanBerlabel()
            ->with(['media:id,nama,partner', 'analisisSentimen' => fn ($q) => $q
                ->where('relevan', true),
            ])
            ->terbitAntara($periode->mulaiUtc(), $periode->akhirUtc())
            ->when($request->query('sentimen'), fn ($q, $label) => $q->whereHas(
                'analisisSentimen',
                fn ($s) => $s->where('relevan', true)
                    ->whereIn('label_efektif', explode(',', $label)),
            ))
            // Daftar id dari kartu topik di ringkasan eksekutif.
            //
            // Id dikirim di alamat, bukan nomor urut topiknya, supaya tautan
            // yang dibuka setelah narasi diregenerasi tetap menampilkan berita
            // yang sama dengan yang terbaca di kartu, bukan topik lain yang
            // kebetulan menempati urutan itu.
            ->when($request->query('artikel'), fn ($q, $daftar) => $q->whereIn(
                'id',
                array_slice(array_filter(array_map(intval(...), explode(',', $daftar))), 0, 200),
            ));

        $artikel = KueriTabel::untuk($kueri, $request)
            ->cari(['judul', 'penulis'])
            ->saring(['media' => 'media_id'])
            ->urut(['judul', 'diambil_at'], 'diambil_at', 'desc')
            ->halaman(20);

        return Inertia::render('eksekutif/ArsipBerita', [
            ...$periode->untukInertia(),
            'opsiBulan' => $ringkasan->bulanTersedia($periode->dari),
            'artikel' => $artikel->through(fn (Artikel $a) => [
                'id' => $a->id,
                'judul' => $a->judul,
                'url' => UrlEksternal::http($a->url),
                'media' => $a->media?->nama,
                'media_partner' => (bool) $a->media?->partner,
                'diambil_at' => $a->diambil_at,
                'label' => $a->analisisSentimen->first()?->label_efektif?->value,
                'perlu_review' => (bool) $a->analisisSentimen->first()?->perlu_review,
                // Alasan model atas labelnya, sama seperti di dashboard dan
                // halaman sentimen. Judul berita sering tidak cukup untuk
                // menilai apakah nadanya masuk akal, dan barisnya di sini
                // sekarang punya ruang untuk memuat kalimat itu. Relasinya
                // sudah ikut dimuat di atas, jadi tidak ada kueri tambahan.
                'ringkasan_ai' => $a->analisisSentimen->first()?->reason_summary,
            ]),
            // Penanda bahwa daftar ini sedang dipersempit ke satu topik.
            // Tanpa penanda, pembaca yang mengetuk kartu topik di ringkasan
            // mendarat di arsip berisi lima belas baris tanpa satu pun petunjuk
            // kenapa sisanya hilang.
            'disaringTopik' => $request->filled('artikel'),
            'opsi' => [
                'media' => Media::query()->orderBy('nama')->get(['id', 'nama'])
                    ->map(fn (Media $m) => ['nilai' => (string) $m->id, 'label' => $m->nama])->all(),
            ],
        ]);
    }
}
