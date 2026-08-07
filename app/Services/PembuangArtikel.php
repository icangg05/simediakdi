<?php

namespace App\Services;

use App\Models\Artikel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Satu-satunya pintu penghapusan artikel.
 *
 * Penghapusan bersifat permanen. Tidak ada nisan URL, tidak ada tempat sampah,
 * dan tidak ada daftar pengecualian. Konsekuensinya diterima: URL yang dihapus
 * bisa masuk lagi pada crawl berikutnya, karena satu-satunya penjaga duplikat
 * sekarang adalah baris yang masih ada di tabel artikel.
 *
 * Database menjaga sisanya. Foreign key dari analisis_sentimen dan
 * antrean_gemini memakai CASCADE, sedangkan pemuatan memakai SET NULL. Baris
 * pemuatan juga menyimpan url, judul, arsip_teks, dan arsip_screenshot_path
 * miliknya sendiri, jadi bukti klaim media tetap utuh setelah artikel yang
 * diklaim hilang.
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

        $boleh = self::kandidat()->whereIn('id', $diminta)->pluck('id');

        $dibuang = 0;

        foreach ($boleh->chunk(500) as $bagian) {
            $dibuang += Artikel::withoutGlobalScopes()->whereIn('id', $bagian)->delete();
        }

        return [
            'dibuang' => $dibuang,
            'dilindungi' => $diminta->count() - $boleh->count(),
        ];
    }
}
