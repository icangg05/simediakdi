<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris dataset beku. Teksnya salinan, bukan tautan ke artikel.
 */
class ItemSnapshotRelevansi extends Model
{
    protected $table = 'item_snapshot_relevansi';

    protected $guarded = ['id'];

    /** Hanya `created_at` yang ada di tabel ini; baris ini tidak pernah disunting. */
    public const UPDATED_AT = null;

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SnapshotDatasetRelevansi::class, 'snapshot_dataset_relevansi_id');
    }

    public function artikel(): BelongsTo
    {
        return $this->belongsTo(Artikel::class);
    }
}
