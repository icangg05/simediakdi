<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Narasi eksekutif satu periode: ringkasan, topik, dan isu perhatian.
 *
 * Hanya dibaca controller. Penulisnya satu, yaitu
 * App\Services\Agregasi\NarasiEksekutif yang dipanggil scheduler.
 */
class NarasiEksekutif extends Model
{
    protected $table = 'narasi_eksekutif';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'dari' => 'date',
            'sampai' => 'date',
            'poin' => 'array',
            'perhatian' => 'array',
            'nada_ringkas' => 'array',
            'topik' => 'array',
            'dibuat_at' => 'datetime',
        ];
    }

    /**
     * Bentuk yang dikirim ke Inertia.
     *
     * `dari` dan `sampai` ikut dikirim karena tidak selalu sama dengan rentang
     * yang sedang dilihat. Saat Gemini gagal semalam, yang tampil adalah narasi
     * kemarin, dan halaman harus mengatakannya alih-alih menyajikan kalimat
     * lama seolah baru.
     *
     * @return array<string, mixed>
     */
    public function untukInertia(): array
    {
        return [
            'nada' => $this->nada,
            'judul' => $this->judul,
            'ringkasan' => $this->ringkasan,
            'penjelasan_tren' => $this->penjelasan_tren,
            'poin' => $this->daftarPoin(),
            'perhatian' => $this->perhatian ?? [],
            'nada_ringkas' => $this->nada_ringkas ?? [],
            'topik' => $this->topik ?? [],
            'dari' => $this->dari->toDateString(),
            'sampai' => $this->sampai->toDateString(),
            'dibuat_at' => $this->dibuat_at,
        ];
    }

    /**
     * Poin dalam satu bentuk, apa pun isi kolomnya.
     *
     * Sebelum poin membawa tautan, kolom ini berisi daftar untaian biasa. Baris
     * lama tidak dimigrasikan karena narasi dibuat ulang penjadwal tiap jam,
     * jadi bentuk lama hilang sendiri dalam sehari. Normalisasi dikerjakan di
     * sini, satu tempat, supaya halaman tidak perlu tahu ada dua bentuk.
     *
     * @return list<array{teks: string, artikel_ids: list<int>}>
     */
    private function daftarPoin(): array
    {
        return array_values(array_map(
            fn ($p) => is_array($p)
                ? ['teks' => (string) ($p['teks'] ?? ''), 'artikel_ids' => array_values((array) ($p['artikel_ids'] ?? []))]
                : ['teks' => (string) $p, 'artikel_ids' => []],
            (array) ($this->poin ?? []),
        ));
    }
}
