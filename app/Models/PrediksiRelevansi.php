<?php

namespace App\Models;

use App\Enums\LabelRelevansi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu prediksi, satu baris. Tidak pernah ditimpa.
 *
 * Probabilitas mentah disimpan, bukan hanya labelnya, supaya penyetelan ambang
 * tidak memerlukan inferensi ulang. Itu sifat paling berharga dari rancangan
 * cosine yang digantikan, dan sengaja dibawa serta.
 */
class PrediksiRelevansi extends Model
{
    protected $table = 'prediksi_relevansi';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'label_prediksi' => LabelRelevansi::class,
            'review_required' => 'boolean',
            'input_truncated' => 'boolean',
            'sinyal' => 'array',
            'predicted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function sampel(): BelongsTo
    {
        return $this->belongsTo(SampelRelevansi::class, 'sampel_relevansi_id');
    }

    public function artikel(): BelongsTo
    {
        return $this->belongsTo(Artikel::class);
    }

    public function versiModel(): BelongsTo
    {
        return $this->belongsTo(VersiModelRelevansi::class, 'versi_model_relevansi_id');
    }
}
