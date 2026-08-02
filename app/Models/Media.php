<?php

namespace App\Models;

use App\Enums\TierMedia;
use App\Models\Scopes\MilikMedia;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

#[ScopedBy(MilikMedia::class)]
class Media extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tier' => TierMedia::class,
            'partner' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    /** Media disaring lewat primary key-nya sendiri, bukan kolom media_id. */
    public function kolomMedia(): string
    {
        return 'id';
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }

    public function sumberFeed(): HasMany
    {
        return $this->hasMany(SumberFeed::class);
    }

    public function artikel(): HasMany
    {
        return $this->hasMany(Artikel::class);
    }

    public function kontrak(): HasMany
    {
        return $this->hasMany(Kontrak::class);
    }

    public function pengguna(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
