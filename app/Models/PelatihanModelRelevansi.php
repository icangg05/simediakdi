<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Satu pelatihan, dari antrean sampai artefak.
 */
class PelatihanModelRelevansi extends Model
{
    use LogsActivity;

    protected $table = 'pelatihan_model_relevansi';

    protected $guarded = ['id'];

    /** Status yang tidak akan berubah lagi tanpa ada yang menekan tombol. */
    public const SELESAI = ['berhasil', 'gagal', 'dibatalkan'];

    protected function casts(): array
    {
        return [
            'konfigurasi' => 'array',
            'metrik' => 'array',
            'riwayat_epoch' => 'array',
            'confusion_matrix' => 'array',
            'laporan_klasifikasi' => 'array',
            'aktif' => 'boolean',
            'batal_diminta' => 'boolean',
            'mulai_at' => 'datetime',
            'selesai_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['nama', 'status', 'aktif', 'batal_diminta'])
            ->logOnlyDirty();
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SnapshotDatasetRelevansi::class, 'snapshot_dataset_relevansi_id');
    }

    public function ujiManual(): HasMany
    {
        return $this->hasMany(UjiManualRelevansi::class, 'pelatihan_model_relevansi_id');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}
