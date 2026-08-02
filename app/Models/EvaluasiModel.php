<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EvaluasiModel extends Model
{
    protected $table = 'evaluasi_model';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'dievaluasi_at' => 'datetime',
            'confusion_matrix' => 'array',
        ];
    }

    /** Angka yang tampil di detail artikel dan footer dashboard eksekutif. */
    public static function terbaru(): ?self
    {
        return static::orderByDesc('dievaluasi_at')->first();
    }
}
