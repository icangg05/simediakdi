<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu versi ambang relevansi. Dokumen 05 bagian 7.3.
 *
 * Ambang disimpan sebagai baris berversi, bukan di `.env`. Alasannya bukan
 * kerapian: mengubah ambang mengubah arti seluruh angka dashboard, dan nilai
 * yang hidup di berkas konfigurasi berubah tanpa meninggalkan jejak siapa yang
 * mengubahnya, kapan, dan atas dasar apa.
 *
 * `reason` wajib diisi karena itulah satu-satunya isi kolom yang berguna enam
 * bulan kemudian, saat ada yang bertanya mengapa angkanya 0,45 dan bukan 0,5.
 */
class VersiThresholdRelevansi extends Model
{
    protected $table = 'versi_threshold_relevansi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'relevant_threshold' => 'float',
            'review_lower_bound' => 'float',
            'review_upper_bound' => 'float',
            'source_overrides' => 'array',
            'activated_at' => 'datetime',
        ];
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
