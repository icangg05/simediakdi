<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanKontrakRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'media_id' => ['required', 'exists:media,id'],
            'nomor' => ['nullable', 'string', 'max:120'],
            'judul' => ['required', 'string', 'max:250'],
            'jenis' => ['required', Rule::in(['advertorial', 'publikasi', 'banner', 'lain'])],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_akhir' => ['required', 'date', 'after_or_equal:tanggal_mulai'],
            'nilai' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'target_pemuatan' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'status' => ['required', Rule::in(['draft', 'aktif', 'selesai', 'batal'])],
            'catatan' => ['nullable', 'string'],
            // Hanya pdf, maksimal 10 MB, dan tipe MIME sebenarnya yang diperiksa
            // , bukan ekstensi berkasnya (dokumen 06 bagian 7).
            'berkas' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh mendahului tanggal mulai.',
            'berkas.mimetypes' => 'Dokumen kontrak harus berkas PDF.',
            'berkas.max' => 'Ukuran dokumen maksimal 10 MB.',
        ];
    }
}
