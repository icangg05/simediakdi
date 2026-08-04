<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Definisi konteks berversi.
 *
 * Model yang dilatih di bawah satu definisi tidak otomatis berlaku di bawah
 * definisi lain. Tanpa versi, perubahan satu kalimat aturan membuat seluruh
 * label lama menjawab pertanyaan yang berbeda tanpa ada yang tahu.
 */
class VersiKonteksRelevansi extends Model
{
    protected $table = 'versi_konteks_relevansi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'aturan_inklusi' => 'array',
            'aturan_eksklusi' => 'array',
            'activated_at' => 'datetime',
        ];
    }
}
