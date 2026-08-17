<?php

namespace App\Http\Controllers\Eksekutif;

use App\Http\Controllers\Controller;
use App\Models\Artikel;
use App\Models\RiwayatAlert;
use App\Services\Agregasi\NarasiEksekutif;
use App\Services\Agregasi\RingkasanEksekutif;
use App\Support\Periode;
use App\Support\UrlEksternal;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman paling penting di seluruh sistem, dan satu-satunya yang dilihat
 * pimpinan. Dirancang mobile lebih dulu (dokumen 04 bagian C.1).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, RingkasanEksekutif $ringkasan, NarasiEksekutif $narasi): Response
    {
        $periode = Periode::dariRequest($request, $ringkasan);

        return Inertia::render('eksekutif/Dashboard', [
            ...$periode->untukInertia(),
            'opsiBulan' => $ringkasan->bulanTersedia($periode->dari),
            'kpi' => $ringkasan->kpi($periode->dari, $periode->sampai),
            'deret' => $ringkasan->deret($periode->dari, $periode->sampai),
            // Deret kedua, dipecah per media. Grafik batang beranimasi memakai
            // ini, dan sumbunya nama media, bukan tanggal.
            'deretMedia' => $ringkasan->deretMedia($periode->dari, $periode->sampai),
            // Dibaca dari tabel, tidak pernah dibuat di sini. Halaman ini tidak
            // boleh menunggu Gemini, dan menyegarkannya tidak boleh menambah
            // satu pun permintaan ke Google.
            'narasi' => $this->narasi($periode, $narasi),
            'peringkatMedia' => $ringkasan->peringkatMedia($periode->dari, $periode->sampai, 6),
            'beritaPerhatian' => $this->berita($periode, 'negatif', 4),
            'beritaPositif' => $this->berita($periode, 'positif', 4),
            'beritaTerbaru' => $this->berita($periode, null, 6),
            // Tanpa batas rentang, jadi kartu ulasan selalu punya satu berita
            // negatif untuk ditunjuk. Rentang yang bersih dari berita negatif
            // adalah keadaan yang sering, dan bagian yang menghilang begitu saja
            // membuat pembaca mengira sistemnya berhenti memeriksa.
            'negatifTerakhir' => $this->berita(null, 'negatif', 1)[0] ?? null,
            'peringatan' => $this->peringatan(),
            // Footer transparansi. Baris ini yang membuat pengguna berpikir
            // "sistemnya memang tidak sempurna dan itu diakui" saat menemukan
            // satu label yang jelas salah, dan itu pasti terjadi.
        ]);
    }

    /**
     * Ringkasan dan topik tulisan Gemini untuk rentang yang sedang dilihat.
     *
     * Empat rentang pintasan memakai narasi terbaru presetnya. Di luar itu,
     * rentang dicocokkan tepat pada tanggalnya, sehingga bulan kalender yang
     * sudah lewat menampilkan ulasan bulan itu sendiri, bukan bagian kosong.
     * Tanggal yang tidak punya narasi tersimpan tetap mengembalikan null, dan
     * halaman menampilkan seluruh statistiknya saja. Tidak ada jalur di sini
     * yang memanggil Gemini, karena narasi hanya dibuat penjadwal.
     *
     * @return array<string, mixed>|null
     */
    private function narasi(Periode $periode, NarasiEksekutif $narasi): ?array
    {
        $preset = $narasi->preset($periode->dari, $periode->sampai);

        return $preset === null
            ? $narasi->padaRentang('30d', $periode->dari, $periode->sampai)?->untukInertia()
            : $narasi->terbaru($preset)?->untukInertia();
    }

    /**
     * Berita relevan dan sudah berlabel, boleh disaring menurut labelnya.
     *
     * Sebelumnya daftar ini mengambil artikel apa pun dan hanya memuat labelnya
     * dengan syarat relevan, sehingga artikel yang sudah dinyatakan tidak
     * relevan tetap muncul di panel pimpinan tanpa label sama sekali.
     *
     * Satu method untuk tiga daftar di layar. Berita terbaru saja tidak cukup:
     * pada periode yang didominasi kegiatan seremonial, satu-satunya berita
     * negatif periode itu bisa terdorong keluar dari enam baris teratas justru
     * pada hari pimpinan paling perlu melihatnya.
     *
     * Periode null berarti seluruh arsip, dipakai kartu ulasan untuk menunjuk
     * berita negatif terakhir saat rentang yang dibuka tidak memuat satu pun.
     *
     * @return list<array<string, mixed>>
     */
    private function berita(?Periode $periode, ?string $label, int $batas): array
    {
        return Artikel::query()
            ->relevanBerlabel()
            ->when($label !== null, fn ($q) => $q->whereHas(
                'analisisSentimen',
                fn ($s) => $s->where('relevan', true)->where('label_efektif', $label),
            ))
            ->with(['media:id,nama,partner', 'analisisSentimen' => fn ($q) => $q
                ->where('relevan', true),
            ])
            ->when($periode !== null, fn ($q) => $q->terbitAntara($periode->mulaiUtc(), $periode->akhirUtc()))
            ->orderByDesc('diambil_at')
            ->limit($batas)
            ->get(['id', 'media_id', 'judul', 'url', 'diambil_at'])
            ->map(fn (Artikel $a) => [
                'id' => $a->id,
                'judul' => $a->judul,
                'url' => UrlEksternal::http($a->url),
                'media' => $a->media?->nama,
                'media_partner' => (bool) $a->media?->partner,
                'diambil_at' => $a->diambil_at,
                'label' => $a->analisisSentimen->first()?->label_efektif?->value,
                'perlu_review' => (bool) $a->analisisSentimen->first()?->perlu_review,
                // Alasan model atas label yang diberikannya. Untuk artikel
                // relevan kolom ini sudah ditimpa tahap sentimen, jadi isinya
                // menjelaskan nadanya dan bukan alasan artikelnya dianggap
                // relevan. Kosong pada baris analisis lama, dan halaman
                // menyembunyikan barisnya kalau begitu.
                'ringkasan_ai' => $a->analisisSentimen->first()?->reason_summary,
            ])
            ->all();
    }

    /**
     * Kartu peringatan hanya dirender kalau ada isinya. Kartu kosong bertuliskan
     * "tidak ada peringatan" menghabiskan ruang layar yang berharga.
     *
     * @return array<string, mixed>|null
     */
    private function peringatan(): ?array
    {
        $baris = RiwayatAlert::query()
            ->whereNull('dibaca_at')
            ->where('dipicu_at', '>=', now()->subDay())
            ->orderByDesc('dipicu_at')
            ->get(['id', 'ringkasan', 'dipicu_at']);

        return $baris->isEmpty() ? null : [
            'jumlah' => $baris->count(),
            'terbaru' => $baris->first()->ringkasan,
            'dipicu_at' => $baris->first()->dipicu_at,
        ];
    }
}
