<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pgvector\Laravel\Vector;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class KonteksPantauan extends Model
{
    use LogsActivity;

    protected $table = 'konteks_pantauan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kata_kunci' => 'array',
            'embedding' => Vector::class,
            'utama' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    public function analisisSentimen(): HasMany
    {
        return $this->hasMany(AnalisisSentimen::class);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('aktif', true)->orderBy('urutan');
    }

    /** Konteks yang menjadi dasar seluruh angka dashboard eksekutif. */
    public static function utama(): ?self
    {
        return static::where('utama', true)->first();
    }
}
