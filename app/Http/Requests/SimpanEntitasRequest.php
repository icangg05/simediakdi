<?php

namespace App\Http\Requests;

use App\Services\Nlp\PencocokEntitas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanEntitasRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:200'],
            'jenis' => ['required', 'in:orang,organisasi,opd,lokasi,program,lain'],
            'alias' => ['nullable', 'array', 'max:50'],
            'alias.*' => ['string', 'max:200'],

            // Keunikan diperiksa terhadap nama_normal, bukan nama. "Wali Kota
            // Kendari" dan "wali kota kendari" adalah entitas yang sama, dan
            // membiarkan keduanya masuk membuat hitungan sebutan terbelah dua.
            'nama_normal' => [
                Rule::unique('entitas', 'nama_normal')->ignore($this->route('entitas')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $pencocok = app(PencocokEntitas::class);

        $this->merge([
            'nama_normal' => $pencocok->normalkan($this->input('nama')),
            'alias' => array_values(array_filter(array_map(
                'trim',
                is_string($this->input('alias'))
                    ? preg_split('/\R/', $this->input('alias'))
                    : (array) $this->input('alias', []),
            ))),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nama_normal.unique' => 'Entitas dengan nama yang sama (mengabaikan huruf besar dan tanda baca) sudah ada. Tambahkan variasi penulisannya sebagai alias, jangan sebagai entitas baru.',
        ];
    }
}
