<?php

namespace App\Http\Requests;

use App\Enums\TierMedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class SimpanMediaRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('media')?->id;

        return [
            'nama' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:150', 'alpha_dash', Rule::unique('media', 'slug')->ignore($id)->withoutTrashed()],
            'jenis' => ['required', Rule::in(['online', 'cetak', 'tv', 'radio'])],
            'tier' => ['required', new Enum(TierMedia::class)],
            'url_website' => ['nullable', 'url', 'max:255'],
            // Subdomain lengkap dipertahankan agar artikel dari domain induk
            // yang berbeda tidak ikut tercocokkan.
            'domain' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9.-]+$/', Rule::unique('media', 'domain')->ignore($id)->withoutTrashed()],
            'kota' => ['nullable', 'string', 'max:100'],
            'provinsi' => ['nullable', 'string', 'max:100'],
            'partner' => ['boolean'],
            'nama_pic' => ['nullable', 'string', 'max:120'],
            'kontak_pic' => ['nullable', 'string', 'max:120'],
            'catatan' => ['nullable', 'string'],
            'aktif' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => $this->slug ?: str($this->nama ?? '')->slug()->value(),
            'domain' => $this->domain ? str($this->domain)->lower()->after('www.')->value() : null,
            'partner' => $this->boolean('partner'),
            'aktif' => $this->boolean('aktif'),
        ]);
    }

    public function messages(): array
    {
        return [
            'domain.unique' => 'Domain ini sudah dipakai media lain. Pencocokan artikel ke media memakai domain, jadi tiap media harus punya domain sendiri.',
            'domain.regex' => 'Isi domain saja tanpa http:// dan tanpa garis miring, contohnya kendaripos.fajar.co.id.',
        ];
    }
}
