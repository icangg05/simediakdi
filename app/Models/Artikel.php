<?php

namespace App\Models;

use App\Models\Scopes\MilikMedia;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Tanggal yang berarti "kapan berita ini muncul".
     *
     * Tanggal terbit dari feed, dan tanggal unduh hanya sebagai cadangan saat
     * feed tidak memuatnya. Sebelumnya seluruh agregasi memakai `diambil_at`,
     * dan akibatnya terlihat di grafik pimpinan: penarikan arsip 3 Agustus
     * memasukkan 1.026 artikel lama ke satu hari, lalu terbaca sebagai lonjakan
     * pemberitaan yang tidak pernah terjadi.
     *
     * Ditaruh di satu tempat karena enam pemanggil memakainya, termasuk dua
     * kueri SQL mentah. Kalau grafik memakai tanggal terbit sedangkan daftar di
     * bawahnya memakai tanggal unduh, jumlah barisnya tidak akan pernah cocok
     * dengan angka di atasnya.
     *
     * @param  string|null  $alias  awalan tabel untuk kueri yang memakai JOIN
     */
    public static function waktuTerbit(?string $alias = null): string
    {
        $awalan = $alias === null ? '' : $alias.'.';

        return "coalesce({$awalan}dipublikasikan_at, {$awalan}diambil_at)";
    }

    /** Artikel yang muncul pada satu rentang, menurut tanggal terbitnya. */
    public function scopeTerbitAntara(Builder $kueri, mixed $mulai, mixed $akhir): Builder
    {
        return $kueri->whereRaw(self::waktuTerbit().' BETWEEN ? AND ?', [$mulai, $akhir]);
    }

    /**
     * Artikel yang lolos relevansi dan sudah punya label sentimen.
     *
     * Ini populasi yang boleh muncul di panel eksekutif. Artikel hasil crawl
     * yang belum diklasifikasi atau sudah dinyatakan tidak relevan tidak
     * dilihat pimpinan, dan setiap angka di panel itu dihitung dari populasi
     * yang sama supaya daftar dan totalnya tidak saling membantah.
     *
     * `label_efektif` adalah kolom generated `COALESCE(label_manual,
     * label_model)`, jadi koreksi admin ikut terbaca tanpa syarat tambahan.
     */
    public function scopeRelevanBerlabel(Builder $kueri): Builder
    {
        return $kueri->whereHas('analisisSentimen', fn (Builder $q) => $q
            ->where('relevan', true)
            ->whereNotNull('label_efektif'));
    }
}
