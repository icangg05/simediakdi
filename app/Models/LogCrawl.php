<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogCrawl extends Model
{
    protected $table = 'log_crawl';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'dimulai_at' => 'datetime',
            'selesai_at' => 'datetime',
        ];
    }

    public function sumberFeed(): BelongsTo
    {
        return $this->belongsTo(SumberFeed::class);
    }
}
