<?php

namespace App\Models;

use App\Models\Scopes\MilikMedia;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

#[ScopedBy(MilikMedia::class)]
class Artikel extends Model
{
    use HasNeighbors;

    protected $table = 'artikel';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
            'dipublikasikan_at' => 'datetime',
            'diambil_at' => 'datetime',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function sumberFeed(): BelongsTo
    {
        return $this->belongsTo(SumberFeed::class);
    }

    public function analisisSentimen(): HasMany
    {
        return $this->hasMany(AnalisisSentimen::class);
    }
}
