<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu pengujian manual di tab Pengujian Model. Tidak pernah menyentuh artikel
 * produksi: halaman uji adalah tempat mencoba, dan tempat mencoba yang
 * diam-diam menulis ke produksi berhenti menjadi tempat mencoba.
 */
class UjiManualRelevansi extends Model
{
    protected $table = 'uji_manual_relevansi';

    protected $guarded = ['id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'probabilitas_relevan' => 'float',
            'probabilitas_tidak_relevan' => 'float',
            'confidence' => 'float',
            'inferensi_ms' => 'integer',
        ];
    }

    public function pelatihan(): BelongsTo
    {
        return $this->belongsTo(PelatihanModelRelevansi::class, 'pelatihan_model_relevansi_id');
    }

    public function penguji(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}
