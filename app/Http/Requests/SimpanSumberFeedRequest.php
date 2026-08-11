<?php

namespace App\Http\Requests;

use App\Enums\TipeSumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class SimpanSumberFeedRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // `media_id` sengaja tidak divalidasi di sini. Sumber feed selalu
            // dibuat lewat rute yang sudah menyebut medianya, dan controller
            // mengambil pemiliknya dari rute itu. Menerimanya dari badan
            // permintaan berarti membuka pemindahan sumber antar media lewat
            // satu field tersembunyi.
            'nama' => ['required', 'string', 'max:150'],
            'tipe' => ['required', new Enum(TipeSumber::class)],
            'url' => ['required', 'url', 'max:500'],
            'selector' => ['nullable', 'array', 'required_if:tipe,scrape,scrape_render'],
            'selector.item' => ['required_with:selector', 'string'],
            'selector.judul' => ['required_with:selector', 'string'],
            'selector.tautan' => ['required_with:selector', 'string'],
            // Saringan opsional untuk feed media nasional yang isinya didominasi
            // berita di luar Kendari.
            'kata_kunci' => ['nullable', 'string', 'max:255'],
            'interval_menit' => ['required', 'integer', 'min:5', 'max:1440'],
            'aktif' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['aktif' => $this->boolean('aktif')]);
    }

    public function messages(): array
    {
        return [
            'selector.required_if' => 'Sumber tipe scraping butuh CSS selector untuk item, judul, dan tautan.',
            'interval_menit.min' => 'Interval minimal 5 menit. Lebih rapat dari itu membebani situs media tanpa menambah berita.',
        ];
    }
}
