<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Satu kunci API Gemini beserta catatan kapan kuotanya pulih.
 *
 * Beberapa kunci ada bukan untuk menaikkan kecepatan, melainkan untuk
 * menyambung kuota. Free tier Gemini membatasi per menit dan per hari, dan
 * batas hariannya baru pulih pada tengah malam waktu Pasifik. Satu kunci
 * berarti klasifikasi berhenti sampai jam itu.
 */
class KunciGemini extends Model
{
    use LogsActivity;

    protected $table = 'kunci_gemini';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kunci' => 'encrypted',
            'aktif' => 'boolean',
            'limit_sampai' => 'datetime',
            'terakhir_dipakai_at' => 'datetime',
        ];
    }

    /** Kunci yang boleh dipanggil sekarang: dinyalakan dan tidak sedang kena limit. */
    public function scopeTersedia(Builder $kueri): void
    {
        $kueri->where('aktif', true)
            ->where(fn (Builder $q) => $q
                ->whereNull('limit_sampai')
                ->orWhere('limit_sampai', '<=', now()));
    }

    public function sedangLimit(): bool
    {
        return $this->limit_sampai !== null && $this->limit_sampai->isFuture();
    }

    /**
     * Kuncinya sendiri tidak pernah ikut tercatat.
     *
     * `logFillable` akan menyalin kolom `kunci` ke tabel activity log dalam
     * bentuk terdekripsi, dan log aktivitas dibaca lebih banyak orang daripada
     * tabel ini.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['label', 'aktif'])
            ->logOnlyDirty();
    }
}
