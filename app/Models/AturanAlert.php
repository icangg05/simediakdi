<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class AturanAlert extends Model
{
    use LogsActivity;

    protected $table = 'aturan_alert';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kondisi' => 'array',
            'penerima' => 'array',
            'aktif' => 'boolean',
            'dipicu_terakhir_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    public function konteks(): BelongsTo
    {
        return $this->belongsTo(KonteksPantauan::class, 'konteks_pantauan_id');
    }

    public function riwayat(): HasMany
    {
        return $this->hasMany(RiwayatAlert::class);
    }

    /** F-40: jangan kirim ulang sebelum jeda_minimal_jam terlewati. */
    public function bolehDikirim(): bool
    {
        return $this->dipicu_terakhir_at === null
            || $this->dipicu_terakhir_at->addHours($this->jeda_minimal_jam)->isPast();
    }
}
