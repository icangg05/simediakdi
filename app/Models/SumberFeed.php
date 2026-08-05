<?php

namespace App\Models;

use App\Enums\TipeSumber;
use App\Models\Scopes\MilikMedia;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[ScopedBy(MilikMedia::class)]
class SumberFeed extends Model
{
    use LogsActivity;

    protected $table = 'sumber_feed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tipe' => TipeSumber::class,
            'selector' => 'array',
            'aktif' => 'boolean',
            'dijalankan_terakhir_at' => 'datetime',
            'berhasil_terakhir_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function logCrawl(): HasMany
    {
        return $this->hasMany(LogCrawl::class);
    }

    /** Sumber aktif yang sudah melewati interval_menit-nya. */
    public function scopeJatuhTempo(Builder $query): Builder
    {
        return $query->where('aktif', true)
            ->where(function (Builder $q) {
                $q->whereNull('dijalankan_terakhir_at')
                    ->orWhereRaw("dijalankan_terakhir_at < now() - (interval_menit * interval '1 minute')");
            });
    }
}
