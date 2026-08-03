<?php

namespace App\Http\Requests;

use App\Enums\PeranPengguna;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;

class SimpanPenggunaRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('pengguna')?->id;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')->ignore($id)->withoutTrashed()],
            // Wajib saat membuat, opsional saat menyunting.
            'password' => [$id ? 'nullable' : 'required', Password::min(8)],
            'peran' => ['required', new Enum(PeranPengguna::class)],
            // Constraint database menolak kombinasi yang salah; validasi di sini
            // supaya pesannya bisa dibaca admin, bukan galat SQL.
            'media_id' => [
                Rule::requiredIf(fn () => $this->input('peran') === PeranPengguna::Media->value),
                'nullable',
                'exists:media,id',
            ],
            'jabatan' => ['nullable', 'string', 'max:120'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'aktif' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'aktif' => $this->boolean('aktif'),
            // Peran selain media tidak boleh punya media_id sama sekali.
            'media_id' => $this->input('peran') === PeranPengguna::Media->value
                ? $this->input('media_id')
                : null,
        ]);
    }

    public function messages(): array
    {
        return [
            'media_id.required' => 'Peran media wajib ditautkan ke satu media. Tanpa itu, pengguna tidak akan melihat data apa pun.',
        ];
    }
}
