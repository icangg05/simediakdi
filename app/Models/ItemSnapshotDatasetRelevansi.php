<?php

namespace App\Models;

use App\Enums\LabelRelevansi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu anggota snapshot, beserta split dan label saat dibekukan.
 */
class ItemSnapshotDatasetRelevansi extends Model
{
    protected $table = 'item_snapshot_dataset_relevansi';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'label_at_snapshot' => LabelRelevansi::class,
            'created_at' => 'datetime',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SnapshotDatasetRelevansi::class, 'snapshot_dataset_relevansi_id');
    }

    public function sampel(): BelongsTo
    {
        return $this->belongsTo(SampelRelevansi::class, 'sampel_relevansi_id');
    }
}
