<?php

namespace App\Models;

use App\Enums\StatusGerbangMutu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Satu versi model relevansi. Hanya satu boleh berstatus `production`,
 * dan yang menjaminnya unique partial index di database, bukan kode ini.
 */
class VersiModelRelevansi extends Model
{
    protected $table = 'versi_model_relevansi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quality_gate_status' => StatusGerbangMutu::class,
            'metrics' => 'array',
            'runtime_info' => 'array',
            'quality_gate_report' => 'array',
            'activated_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function gerbangMutu(): HasMany
    {
        return $this->hasMany(GerbangMutuRelevansi::class);
    }

    /** Penilaian gerbang terakhir, yang menentukan boleh tidaknya sentimen jalan. */
    public function gerbangTerakhir(): HasOne
    {
        return $this->hasOne(GerbangMutuRelevansi::class)->latestOfMany();
    }

    public function prediksi(): HasMany
    {
        return $this->hasMany(PrediksiRelevansi::class);
    }

    public function scopeProduksi(Builder $query): Builder
    {
        return $query->where('status', 'production');
    }
}
