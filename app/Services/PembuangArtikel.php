<?php

namespace App\Services;

use App\Enums\StatusDedup;
use App\Models\Artikel;
use App\Models\UrlDibuang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya pintu penghapusan artikel.
 *
 * Satu aturan hidup di sini, dan ia pernah dilanggar karena penghapusan
 * dilakukan langsung lewat query builder di tempat lain. URL yang dibuang harus
 * dicatat di nisan. Tanpa itu crawl berikutnya memasukkannya lagi sebagai
 * berita baru, dan Gemini menilainya untuk kedua kalinya demi jawaban yang sama.
 *
 * Dulu ada aturan kedua, sebuah daftar pengecualian bernama lindungi(). Ia
 * menahan artikel yang punya laporan pemuatan dan artikel yang jadi induk
 * salinan. Hasilnya 191 dari 210 artikel yang layak dibuang tertahan diam-diam,
 * dan tombol buang seolah tidak berfungsi.
 *
 * Daftar itu dicopot karena database sudah menjaga dirinya sendiri. Foreign key
 * dari analisis_sentimen dan antrean_gemini memakai CASCADE, sedangkan pemuatan
 * dan artikel.artikel_induk_id memakai SET NULL. Baris pemuatan juga menyimpan
 * url, judul, arsip_teks, dan arsip_screenshot_path miliknya sendiri, jadi bukti
 * klaim media tetap utuh setelah artikel yang diklaim hilang.
 */
class PembuangArtikel
{
    /**
     * Artikel yang boleh dibuang dari halaman Antrean AI.
     *
     * Tidak relevan dan perlu review, dua kelompok yang sudah dinilai dan tidak
     * dipakai laporan apa pun. Artikel relevan tidak pernah masuk daftar ini,
     * berapa pun sentimennya, karena justru itu isi laporan. Itu satu-satunya
     * batas yang tersisa, dan di dalam batas itu tidak ada lagi pengecualian.
     */
    public static function kandidat(): Builder
    {
        return Artikel::query()->where(function (Builder $q) {
            $q->whereHas('analisisSentimen', fn (Builder $a) => $a->where('relevan', false))
                ->orWhere('status_proses', 'perlu_review');
        });
    }

    /**
     * @param  Collection<int, int>|list<int>  $id
     * @return array{dibuang: int, dilindungi: int}
     */
    public function buang(Collection|array $id, string $alasan): array
    {
        $diminta = collect($id)->map(fn ($satu): int => (int) $satu)->unique();

        if ($diminta->isEmpty()) {
            return ['dibuang' => 0, 'dilindungi' => 0];
        }

        $boleh = self::kandidat()->whereIn('id', $diminta)->pluck('url_kanonik', 'id');

        $dibuang = 0;

        foreach ($boleh->chunk(500) as $bagian) {
            DB::transaction(function () use ($bagian, $alasan, &$dibuang) {
                // Nisan ditulis lebih dulu, di dalam transaksi yang sama.
                // Kalau penghapusannya gagal, nisannya ikut batal, dan tidak
                // ada URL yang tertahan untuk artikel yang masih hidup.
                UrlDibuang::insertOrIgnore(
                    $bagian->map(fn (?string $url): array => [
                        'url_kanonik' => $url,
                        'alasan' => $alasan,
                        'dibuang_at' => now(),
                    ])->filter(fn (array $baris): bool => $baris['url_kanonik'] !== null)->values()->all(),
                );

                // Salinan yang induknya ikut terbuang dipromosikan jadi asli.
                //
                // Ini wajib, bukan kerapian. Constraint chk_artikel_induk_sesuai_dedup
                // menuntut status_dedup 'salinan' selalu punya artikel_induk_id.
                // Menghapus induknya memicu FK SET NULL, dan baris salinannya
                // seketika melanggar constraint itu. Tanpa promosi ini seluruh
                // transaksi gagal dengan SQLSTATE 23514, jadi satu induk saja
                // cukup untuk menggagalkan buang semua.
                Artikel::withoutGlobalScopes()
                    ->whereIn('artikel_induk_id', $bagian->keys())
                    ->update(['artikel_induk_id' => null, 'status_dedup' => StatusDedup::Asli]);

                $dibuang += Artikel::withoutGlobalScopes()->whereIn('id', $bagian->keys())->delete();
            });
        }

        return [
            'dibuang' => $dibuang,
            'dilindungi' => $diminta->count() - $boleh->count(),
        ];
    }
}
