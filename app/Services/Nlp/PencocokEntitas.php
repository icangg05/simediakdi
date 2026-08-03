<?php

namespace App\Services\Nlp;

use App\Models\Artikel;
use App\Models\Entitas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pencocokan entitas berbasis kamus (F-17).
 *
 * Bukan NER model. Pilihan ini sadar, dan alasannya bukan kemalasan:
 *
 * Entitas yang dipantau Pemkot Kendari adalah daftar tertutup dan pendek, nama
 * pejabat, OPD, kelurahan, dan program. Daftarnya sudah diketahui, berubah
 * beberapa kali setahun, dan salah tulisnya bisa langsung diperbaiki admin
 * lewat alias. Model NER berbahasa Indonesia akan menambah satu model lagi ke
 * layanan NLP, salah mengeja nama lokal yang tidak ada di data latihnya, dan
 * kesalahannya tidak bisa dikoreksi tanpa melatih ulang.
 *
 * Kamus kalah pada nama yang belum terdaftar. Itu diterima: yang belum
 * terdaftar memang belum dipantau siapa pun.
 */
class PencocokEntitas
{
    /**
     * Menghitung sebutan entitas untuk satu artikel dan menulis pivotnya.
     *
     * @return int jumlah entitas berbeda yang ditemukan
     */
    public function cocokkan(Artikel $artikel, ?Collection $kamus = null): int
    {
        $kamus ??= $this->kamus();
        $teks = $this->normalkan($artikel->judul.' '.$artikel->isi);

        if ($teks === '') {
            return 0;
        }

        $baris = [];

        foreach ($kamus as $entitas) {
            $jumlah = 0;

            foreach ($entitas['bentuk'] as $bentuk) {
                $jumlah += $this->hitung($teks, $bentuk);
            }

            if ($jumlah > 0) {
                $baris[] = [
                    'artikel_id' => $artikel->id,
                    'entitas_id' => $entitas['id'],
                    // smallint di database. Artikel yang menyebut satu nama
                    // ribuan kali bukan berita, tapi tetap tidak boleh membuat
                    // penyimpanan gagal.
                    'jumlah_sebutan' => min($jumlah, 32767),
                ];
            }
        }

        // Ditulis ulang seluruhnya, bukan ditambahkan. Analisis ulang setelah
        // admin memperbaiki alias harus menghapus sebutan yang sudah tidak
        // berlaku, bukan menumpuk di atasnya.
        DB::transaction(function () use ($artikel, $baris) {
            DB::table('artikel_entitas')->where('artikel_id', $artikel->id)->delete();

            if ($baris !== []) {
                DB::table('artikel_entitas')->insert($baris);
            }
        });

        return count($baris);
    }

    /**
     * Daftar entitas beserta seluruh bentuk penulisannya, sudah dinormalkan.
     *
     * Dimuat sekali lalu dioper ke tiap artikel. Membacanya ulang per artikel
     * berarti satu kueri kali jumlah artikel, dan penarikan arsip memproses
     * ribuan sekaligus.
     *
     * Entitas yang sudah digabungkan tidak ikut: sebutannya harus jatuh ke
     * entitas induk, bukan terhitung dua kali.
     *
     * @return Collection<int, array{id: int, bentuk: list<string>}>
     */
    public function kamus(): Collection
    {
        return Entitas::query()
            ->whereNull('digabung_ke')
            ->get(['id', 'nama', 'nama_normal', 'alias'])
            ->map(fn (Entitas $e) => [
                'id' => $e->id,
                'bentuk' => collect([$e->nama_normal, ...(array) ($e->alias ?? [])])
                    ->map(fn (string $b) => $this->normalkan($b))
                    ->filter()
                    // Bentuk sangat pendek mencocoki apa saja. "PU" akan
                    // muncul di dalam ribuan kata biasa.
                    ->filter(fn (string $b) => mb_strlen($b) >= 3)
                    ->unique()
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $e) => $e['bentuk'] !== [])
            ->values();
    }

    /**
     * Hitung sebutan pada batas kata, bukan substring.
     *
     * Tanpa batas kata, "Kendari" ikut terhitung di dalam "Kendarian" dan
     * entitas lokasi jadi tampak jauh lebih ramai daripada kenyataannya.
     */
    private function hitung(string $teks, string $bentuk): int
    {
        return preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote($bentuk, '/').'(?![\p{L}\p{N}])/u', $teks);
    }

    /** Huruf kecil, tanda baca jadi spasi, spasi ganda dirapatkan. */
    public function normalkan(?string $teks): string
    {
        $teks = mb_strtolower(trim((string) $teks));
        $teks = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $teks) ?? '';

        return trim(preg_replace('/\s+/', ' ', $teks) ?? '');
    }
}
