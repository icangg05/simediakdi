<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Satu dataset beku beserta resep pembuatannya.
 */
class SnapshotDatasetRelevansi extends Model
{
    use LogsActivity;

    protected $table = 'snapshot_dataset_relevansi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'persen_relevan' => 'integer',
            'persen_tidak_relevan' => 'integer',
            'persen_train' => 'integer',
            'persen_validation' => 'integer',
            'persen_test' => 'integer',
            'random_seed' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nama', 'status', 'random_seed', 'total'])
            ->logOnlyDirty();
    }

    public function item(): HasMany
    {
        return $this->hasMany(ItemSnapshotRelevansi::class, 'snapshot_dataset_relevansi_id');
    }

    public function pelatihan(): HasMany
    {
        return $this->hasMany(PelatihanModelRelevansi::class, 'snapshot_dataset_relevansi_id');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}
