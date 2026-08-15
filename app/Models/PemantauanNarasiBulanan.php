<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Keadaan pemeriksaan Gemini untuk satu laporan bulan kalender. */
class PemantauanNarasiBulanan extends Model
{
    public const STATUS_MENUNGGU = 'menunggu';

    public const STATUS_BERJALAN = 'berjalan';

    public const STATUS_SELESAI = 'selesai';

    public const STATUS_GAGAL = 'gagal';

    public const STATUS_TANPA_DATA = 'tanpa_data';

    protected $table = 'pemantauan_narasi_bulanan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bulan' => 'date',
            'dikunci' => 'boolean',
            'pemeriksaan' => 'integer',
            'mulai_at' => 'datetime',
            'selesai_at' => 'datetime',
            'gagal_at' => 'datetime',
        ];
    }

    /** Hasil terakhir yang berhasil untuk bulan ini, jika ada. */
    public function narasi(): BelongsTo
    {
        return $this->belongsTo(NarasiEksekutif::class, 'narasi_eksekutif_id');
    }
}
