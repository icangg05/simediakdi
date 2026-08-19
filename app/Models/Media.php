<?php

namespace App\Models;

use App\Enums\TierMedia;
use App\Models\Scopes\MilikMedia;
use App\Support\UrlEksternal;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[ScopedBy(MilikMedia::class)]
class Media extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'media';

    protected $guarded = ['id'];

    /** Dikirim ke seluruh halaman yang menampilkan media, supaya tautannya selalu tersedia. */
    protected $appends = ['url_publik'];

    protected function casts(): array
    {
        return [
            'tier' => TierMedia::class,
            'partner' => 'boolean',
            'aktif' => 'boolean',
        ];
    }

    /**
     * Alamat situs media, siap dipasang sebagai tujuan tautan.
     *
     * Dua kolom menyimpan hal yang mirip: `url_website` diisi manual dan sering
     * kosong, sedangkan `domain` selalu ada karena crawler memakainya. Yang
     * pertama dipakai lebih dulu, dan domain menambal sisanya dengan skema HTTPS.
     *
     * Melewati `UrlEksternal::http()` supaya aturan tautan luar hanya ditulis
     * sekali. Kolomnya diisi manusia lewat form admin, jadi isinya bisa berupa
     * apa saja, termasuk skema yang tidak boleh dipasang pada tautan.
     */
    protected function urlPublik(): Attribute
    {
        return Attribute::get(fn (): ?string => UrlEksternal::http($this->url_website)
            ?? UrlEksternal::http('https://'.ltrim((string) $this->domain, '/')));
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

    public function pengguna(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
