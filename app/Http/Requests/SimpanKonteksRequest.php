<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SimpanKonteksRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('konteks')?->id;

        return [
            // Dikirim apa adanya ke model sebagai input konteks, jadi tulis
            // seperti kalimat yang wajar dibaca, bukan sebagai kode.
            'nama' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:200', 'alpha_dash', Rule::unique('konteks_pantauan', 'slug')->ignore($id)],
            'deskripsi' => ['nullable', 'string'],
            'kata_kunci' => ['nullable', 'array'],
            'kata_kunci.*' => ['string', 'max:120'],
            'utama' => ['boolean'],
            'urutan' => ['integer', 'min:0', 'max:999'],
            'aktif' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => $this->slug ?: str($this->nama ?? '')->slug()->value(),
            'kata_kunci' => $this->bersihkanKataKunci(),
            'utama' => $this->boolean('utama'),
            'aktif' => $this->boolean('aktif'),
            'urutan' => (int) ($this->urutan ?? 0),
        ]);
    }

    /**
     * Form mengirim satu textarea dipisah baris; simpan sebagai array bersih
     * supaya penyaring tidak perlu menebak formatnya.
     *
     * @return list<string>|null
     */
    private function bersihkanKataKunci(): ?array
    {
        $mentah = $this->input('kata_kunci');

        if (is_string($mentah)) {
            $mentah = preg_split('/[\r\n,]+/', $mentah) ?: [];
        }

        if (! is_array($mentah)) {
            return null;
        }

        $bersih = array_values(array_unique(array_filter(
            array_map(fn ($k) => trim(mb_strtolower((string) $k)), $mentah),
            fn (string $k) => $k !== '',
        )));

        return $bersih === [] ? null : $bersih;
    }
}
