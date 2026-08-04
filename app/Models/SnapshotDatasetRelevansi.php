<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Susunan dataset yang dibekukan untuk satu eksperimen.
 *
 * Label yang berubah setelah snapshot dibuat tidak boleh diam-diam mengubah
 * eksperimen lama. Itu sebabnya label ikut disalin ke tiap item, bukan dibaca
 * lewat join saat evaluasi dijalankan ulang: dua evaluasi atas snapshot yang
 * sama harus menghasilkan angka yang sama meski datasetnya sudah tumbuh.
 */
class SnapshotDatasetRelevansi extends Model
{
    protected $table = 'snapshot_dataset_relevansi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'random_seed' => 'integer',
        ];
    }

    public function item(): HasMany
    {
        return $this->hasMany(ItemSnapshotDatasetRelevansi::class, 'snapshot_dataset_relevansi_id');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pengunci(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function terkunci(): bool
    {
        return $this->status === 'locked';
    }

    /**
     * Sengaja tidak bernama `terkunci`: scope dan predikat instance dengan nama
     * sama membuat pemanggilan statiknya jatuh ke method instance, dan galatnya
     * baru muncul saat dijalankan.
     */
    public function scopeSudahTerkunci(Builder $query): Builder
    {
        return $query->where('status', 'locked');
    }
}
