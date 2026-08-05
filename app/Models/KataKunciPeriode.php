<?php

namespace App\Models;

use App\Enums\LabelSentimen;
use Illuminate\Database\Eloquent\Model;

class KataKunciPeriode extends Model
{
    protected $table = 'kata_kunci_periode';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'periode_mulai' => 'date',
            'periode_akhir' => 'date',
            'sentimen_dominan' => LabelSentimen::class,
            'created_at' => 'datetime',
        ];
    }
}
