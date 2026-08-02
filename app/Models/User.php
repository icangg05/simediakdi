<?php

namespace App\Models;

use App\Enums\PeranPengguna;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, LogsActivity, Notifiable, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'peran',
        'media_id',
        'jabatan',
        'telepon',
        'aktif',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Samakan dengan default kolom di database. Tanpa ini, instance yang baru
     * dibuat punya aktif = null, dan pemeriksaan peran menolaknya.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'aktif' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'peran' => PeranPengguna::class,
            'aktif' => 'boolean',
            'login_terakhir_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'peran', 'media_id', 'jabatan', 'aktif'])
            ->logOnlyDirty();
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function berperan(PeranPengguna ...$peran): bool
    {
        return in_array($this->peran, $peran, strict: true);
    }
}
