<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Nisan URL artikel yang sudah dibuang, dibaca PencatatArtikel sebelum
 * menyimpan item feed.
 */
class UrlDibuang extends Model
{
    protected $table = 'url_dibuang';

    public $timestamps = false;

    protected $fillable = ['url_kanonik', 'alasan', 'dibuang_at'];

    protected function casts(): array
    {
        return ['dibuang_at' => 'datetime'];
    }
}
