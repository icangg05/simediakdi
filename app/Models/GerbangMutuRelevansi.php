<?php

namespace App\Models;

use App\Enums\StatusGerbangMutu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil penilaian gerbang mutu atas satu versi model.
 *
 * `standar` menyimpan syarat yang berlaku saat penilaian dijalankan, bukan
 * dibaca ulang dari pengaturan saat laporan dibuka. Standar bisa diubah, dan
 * laporan yang ikut berubah artinya setelah standarnya diturunkan adalah
 * laporan yang tidak membuktikan apa pun.
 */
class GerbangMutuRelevansi extends Model
{
    protected $table = 'gerbang_mutu_relevansi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => StatusGerbangMutu::class,
            'standar' => 'array',
            'hasil' => 'array',
            'failed_checks' => 'array',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function versiModel(): BelongsTo
    {
        return $this->belongsTo(VersiModelRelevansi::class, 'versi_model_relevansi_id');
    }
}
