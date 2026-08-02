<?php

namespace App\Models;

use App\Enums\StatusVerifikasi;
use App\Models\Scopes\MilikMedia;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

#[ScopedBy(MilikMedia::class)]
class Pemuatan extends Model
{
    use LogsActivity;

    protected $table = 'pemuatan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tanggal_muat' => 'date',
            'status_verifikasi' => StatusVerifikasi::class,
            'arsip_diambil_at' => 'datetime',
            'diverifikasi_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    public function kontrak(): BelongsTo
    {
        return $this->belongsTo(Kontrak::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function artikel(): BelongsTo
    {
        return $this->belongsTo(Artikel::class);
    }

    public function pelapor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dilaporkan_oleh');
    }

    public function verifikator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diverifikasi_oleh');
    }
}
