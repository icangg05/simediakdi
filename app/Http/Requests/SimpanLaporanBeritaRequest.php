<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimpanLaporanBeritaRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'baris' => ['required', 'array', 'min:1', 'max:50'],
            'baris.*.url' => ['required', 'url:http,https', 'max:1000'],
            // Judul datang dari ekstraksi pada kasus normal, dan dari isian
            // media hanya saat halamannya gagal dibaca (F-51). Tetap wajib di
            // sini: artikel tanpa judul tidak bisa dikenali di daftar mana pun.
            'baris.*.judul' => ['required', 'string', 'max:500'],
            'baris.*.tanggal' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'baris.required' => 'Tidak ada tautan yang dikirim.',
            'baris.*.tanggal.before_or_equal' => 'Tanggal terbit tidak boleh di masa depan.',
            'baris.*.judul.required' => 'Judul wajib diisi untuk tautan yang gagal dibaca otomatis.',
        ];
    }
}
