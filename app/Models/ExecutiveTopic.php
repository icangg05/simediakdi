<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExecutiveTopic extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'article_ids' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
