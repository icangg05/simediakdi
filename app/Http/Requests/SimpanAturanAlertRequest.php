<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimpanAturanAlertRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:150'],
            'jenis' => ['required', 'in:lonjakan_negatif,kata_kunci_muncul,sumber_mati,kontrak_tertinggal'],
            'konteks_pantauan_id' => ['nullable', 'integer', 'exists:konteks_pantauan,id'],
            'ambang' => ['nullable', 'numeric', 'min:0'],

            // Batas bawah 1 jam. Jendela di bawah itu membandingkan angka yang
            // terlalu kecil untuk berarti apa pun, dan alert-nya menyala terus.
            'jendela_jam' => ['required', 'integer', 'min:1', 'max:168'],
            'jeda_minimal_jam' => ['required', 'integer', 'min:1', 'max:168'],

            'kanal' => ['required', 'in:telegram,email'],
            'penerima' => ['nullable', 'array'],
            'penerima.*' => ['string', 'max:200'],
            'aktif' => ['boolean'],

            'kondisi' => ['nullable', 'array'],
            'kondisi.minimal_artikel' => ['nullable', 'integer', 'min:1'],
            'kondisi.kelipatan_dari_rata_rata' => ['nullable', 'numeric', 'min:1'],
            'kondisi.abaikan_perlu_review' => ['nullable', 'boolean'],
            'kondisi.istilah' => ['nullable', 'array'],
            'kondisi.istilah.*' => ['string', 'max:100'],
            'kondisi.jam' => ['nullable', 'integer', 'min:1', 'max:720'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Aturan jenis `kata_kunci_muncul` tanpa istilah tidak pernah memicu
        // apa pun. Dijadikan galat validasi, bukan aturan yang diam-diam mati.
        if ($this->input('jenis') === 'kata_kunci_muncul') {
            $istilah = $this->input('kondisi.istilah');

            if (is_string($istilah)) {
                $this->merge([
                    'kondisi' => [
                        ...(array) $this->input('kondisi', []),
                        'istilah' => array_values(array_filter(array_map('trim', preg_split('/\R/', $istilah)))),
                    ],
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('jenis') === 'kata_kunci_muncul'
                && $this->input('kondisi.istilah', []) === []) {
                $v->errors()->add('kondisi.istilah', 'Aturan kata kunci tanpa istilah tidak akan pernah memicu apa pun.');
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'jendela_jam' => 'jendela waktu',
            'jeda_minimal_jam' => 'jeda minimal',
            'kondisi.istilah' => 'daftar istilah',
        ];
    }
}
