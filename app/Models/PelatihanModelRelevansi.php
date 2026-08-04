<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu training run beserta konfigurasi, progres, dan hasilnya.
 *
 * Riwayat kegagalan tidak pernah dihapus. `parent_run_id` menautkan percobaan
 * ulang ke percobaan yang gagal, bukan menggantikannya, karena satu-satunya
 * cara mengetahui konfigurasi mana yang sudah dicoba dan tidak perlu diulang
 * adalah dengan menyimpannya.
 */
class PelatihanModelRelevansi extends Model
{
    protected $table = 'pelatihan_model_relevansi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'runtime_info' => 'array',
            'metrics_validation' => 'array',
            'metrics_test' => 'array',
            'artifact_manifest' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(SnapshotDatasetRelevansi::class, 'snapshot_dataset_relevansi_id');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function induk(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    /** Status yang berarti pekerjaannya sudah berhenti, apa pun hasilnya. */
    public function selesai(): bool
    {
        return in_array($this->status, ['selesai', 'gagal', 'dibatalkan'], strict: true);
    }
}
