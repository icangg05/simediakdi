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
            'poin' => $this->poin ?? [],
            'perhatian' => $this->perhatian ?? [],
            'nada_ringkas' => $this->nada_ringkas ?? [],
            'topik' => $this->topik ?? [],
            'dari' => $this->dari->toDateString(),
            'sampai' => $this->sampai->toDateString(),
            'dibuat_at' => $this->dibuat_at,
        ];
    }
}
