<?php

namespace App\Models;

use App\Enums\LabelRelevansi;
use App\Enums\StatusLabelRelevansi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Satu kandidat dataset relevansi beserta keputusan manusia atasnya.
 *
 * Sengaja menyalin judul, isi, dan metadata artikel alih-alih hanya menyimpan
 * `artikel_id`. Sampel harus tetap utuh meski artikelnya nanti dihapus retensi,
 * dan teks yang dinilai pelabel harus tetap sama persis dengan teks yang
 * dilatihkan ke model. Artikel yang disunting media setelah dilabeli akan
 * mengubah keduanya diam-diam kalau dibaca lewat join.
 */
class SampelRelevansi extends Model
{
    use LogsActivity;

    protected $table = 'sampel_relevansi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'label_manual' => LabelRelevansi::class,
            'status_label' => StatusLabelRelevansi::class,
            'kategori_sumber' => 'array',
            'tag_sumber' => 'array',
            'metadata_sumber' => 'array',
            'priority_reasons' => 'array',
            'is_excluded' => 'boolean',
            'tanggal_publikasi' => 'datetime',
            'labeled_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    /**
     * Label adalah bahan baku model. Label yang salah akan diajarkan dan
     * menjadi kesalahan permanen sampai ada yang menelusurinya kembali, jadi
     * setiap perubahannya dicatat beserta nilai sebelum dan sesudahnya.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['label_manual', 'alasan_label', 'status_label', 'tingkat_kesulitan', 'is_excluded', 'excluded_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function artikel(): BelongsTo
    {
        return $this->belongsTo(Artikel::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function pelabel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'labeled_by');
    }

    public function prediksi(): HasMany
    {
        return $this->hasMany(PrediksiRelevansi::class);
    }

    /** Kandidat yang masih menunggu keputusan manusia. */
    public function scopeBelumDilabeli(Builder $query): Builder
    {
        return $query->where('status_label', StatusLabelRelevansi::BelumDilabeli)
            ->where('is_excluded', false);
    }

    /**
     * Sampel yang boleh masuk snapshot: keputusan final, tidak dikeluarkan.
     *
     * Dipakai penghitung kesiapan data maupun pembuat snapshot, dan sengaja
     * satu tempat. Dua definisi "layak latih" yang berbeda di dua tempat akan
     * menghasilkan angka kesiapan yang tidak cocok dengan isi snapshot.
     */
    public function scopeLayakLatih(Builder $query): Builder
    {
        return $query->where('is_excluded', false)
            ->whereNotNull('label_manual')
            ->whereIn('status_label', [
                StatusLabelRelevansi::SudahDilabeli,
                StatusLabelRelevansi::TerkunciTest,
            ]);
    }
}
