<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExecutiveSummary extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'key_points' => 'array',
            'attention_required' => 'array',
            'sentiment_summary' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
