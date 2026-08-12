<?php

namespace App\Models;

use App\Enums\PeranPengguna;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
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

    /**
     * Username adalah kredensial login, jadi tidak boleh kosong. Registrasi
     * mandiri tidak dibuka, dan akun bisa lahir dari halaman admin, seeder,
     * atau factory. Pengisiannya ditaruh di sini supaya ketiganya lewat satu
     * jalan yang sama.
     */
    protected static function booted(): void
    {
        static::creating(function (User $pengguna) {
            $pengguna->username ??= self::usernameDari($pengguna->email);
        });
    }

    /**
     * Bentuk username dari bagian nama pada email, misal "humas.setda" untuk
     * humas.setda@kendarikota.go.id.
     *
     * Bagian nama tidak dijamin unik antar domain. Ke-29 akun media memakai
     * portal@ semua, jadi angka urut akan menghasilkan portal2 sampai portal29.
     * Tidak ada yang bisa mengingat nomornya. Karena itu yang bentrok memakai
     * nama domain dulu, misal portal.radarkendari, dan angka urut hanya dipakai
     * kalau itu pun masih bentrok.
     */
    public static function usernameDari(string $email): string
    {
        $dasar = Str::slug(Str::before($email, '@'), '.') ?: 'pengguna';

        if (! self::usernameTerpakai($dasar)) {
            return $dasar;
        }

        $domain = Str::slug(Str::before(Str::after($email, '@'), '.'), '.');
        $dasar = $domain ? "{$dasar}.{$domain}" : $dasar;
        $username = $dasar;
        $urutan = 1;

        while (self::usernameTerpakai($username)) {
            $username = $dasar.++$urutan;
        }

        return $username;
    }

    private static function usernameTerpakai(string $username): bool
    {
        return self::withTrashed()->where('username', $username)->exists();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'username', 'email', 'peran', 'media_id', 'jabatan', 'aktif'])
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
