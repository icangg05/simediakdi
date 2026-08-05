<?php

namespace App\Models;

use App\Enums\LabelSentimen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class AnalisisSentimen extends Model
{
    use LogsActivity;

    protected $table = 'analisis_sentimen';

    /** label_efektif adalah kolom generated; menulisnya ditolak Postgres. */
    protected $guarded = ['id', 'label_efektif'];

    protected function casts(): array
    {
        return [
            'relevan' => 'boolean',
            'perlu_review' => 'boolean',
            'fallback_used' => 'boolean',
            'evidence' => 'array',
            'label_model' => LabelSentimen::class,
            'label_manual' => LabelSentimen::class,
            'label_efektif' => LabelSentimen::class,
            'dianalisis_at' => 'datetime',
            'dikoreksi_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['label_manual', 'dikoreksi_oleh', 'catatan_koreksi'])
            ->logOnlyDirty();
    }

    public function artikel(): BelongsTo
    {
        return $this->belongsTo(Artikel::class);
    }

    public function pengoreksi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dikoreksi_oleh');
    }
}
