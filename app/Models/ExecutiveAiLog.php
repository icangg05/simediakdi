<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExecutiveAiLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }
}
