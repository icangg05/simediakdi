<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiwayatAlert extends Model
{
    protected $table = 'riwayat_alert';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'dipicu_at' => 'datetime',
            'payload' => 'array',
            'dibaca_at' => 'datetime',
        ];
    }

    public function aturan(): BelongsTo
    {
        return $this->belongsTo(AturanAlert::class, 'aturan_alert_id');
    }
}
