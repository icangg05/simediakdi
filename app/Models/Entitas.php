<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Entitas extends Model
{
    protected $table = 'entitas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['alias' => 'array'];
    }

    public function artikel(): BelongsToMany
    {
        return $this->belongsToMany(Artikel::class, 'artikel_entitas')
            ->withPivot('jumlah_sebutan');
    }

    /** Entitas induk saat admin menggabungkan duplikat. */
    public function induk(): BelongsTo
    {
        return $this->belongsTo(self::class, 'digabung_ke');
    }
}
