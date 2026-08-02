<?php

namespace App\Models\Scopes;

use App\Enums\PeranPengguna;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Pengguna peran `media` hanya melihat barisnya sendiri.
 *
 * Ini satu-satunya fitur yang, kalau bocor, menimbulkan masalah dengan pihak
 * luar. Karena itu penyaringan ditaruh di scope global, bukan di tiap query.
 *
 * Model menentukan kolomnya lewat kolomMedia(); default `media_id`, sedangkan
 * model Media sendiri memakai `id`.
 */
class MilikMedia implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user || $user->peran !== PeranPengguna::Media) {
            return;
        }

        $kolom = method_exists($model, 'kolomMedia') ? $model->kolomMedia() : 'media_id';

        // media_id null pada user peran media dicegah oleh constraint database,
        // tapi kalau tetap terjadi jangan bocorkan apa pun.
        $builder->where($model->qualifyColumn($kolom), $user->media_id ?? -1);
    }
}
