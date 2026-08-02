<?php

namespace App\Models;

use App\Enums\LabelSentimen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoldSet extends Model
{
    protected $table = 'gold_set';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'label_gold' => LabelSentimen::class,
            'relevan_gold' => 'boolean',
            'dilabeli_at' => 'datetime',
        ];
    }

    public function artikel(): BelongsTo
    {
        return $this->belongsTo(Artikel::class);
    }

    public function konteks(): BelongsTo
    {
        return $this->belongsTo(KonteksPantauan::class, 'konteks_pantauan_id');
    }

    public function pelabel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dilabeli_oleh');
    }
}
