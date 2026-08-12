<?php

namespace App\Http\Requests;

use App\Enums\PeranPengguna;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
            /*
             * Wajib saat menyunting, kosong boleh saat membuat.
             *
             * Bedanya bukan kelalaian. Saat membuat, username yang dikosongkan
             * dibentuk User::booted() dari bagian nama pada emailnya, dan itu
             * yang membuat 30 akun media bisa didaftarkan tanpa memikirkan
             * username satu per satu. Saat menyunting, akunnya sudah punya
             * username dan itu kredensial masuknya, jadi mengosongkannya bukan
             * "biarkan apa adanya" melainkan "cabut cara masuknya".
             *
             * Uniknya diperiksa termasuk terhadap akun yang sudah dinonaktifkan
             * lewat soft delete, sengaja tanpa `withoutTrashed()`. Indeks unik
             * di database tidak mengenal soft delete, jadi validasi yang
             * melewatkan baris terhapus akan lolos di sini lalu gagal sebagai
             * galat SQL mentah di layar admin. Aturan ini sengaja dibuat sama
             * dengan User::usernameTerpakai(), yang juga memakai withTrashed().
             *
             * Bentuknya dibatasi huruf kecil, angka, titik, garis bawah, dan
             * garis hubung. Ini kredensial yang diketik saat masuk, dan username
             * yang mengandung spasi atau huruf besar menghasilkan kegagalan
             * masuk yang tidak bisa dilihat penyebabnya oleh yang mengetiknya.
             * Nilainya sudah dijadikan huruf kecil di prepareForValidation,
             * jadi aturan ini hanya menolak yang benar-benar tidak berbentuk.
             */
            'username' => [
                $id ? 'required' : 'nullable',
                'string',
                'max:60',
                'regex:/^[a-z0-9][a-z0-9._-]*$/',
                Rule::unique('users', 'username')->ignore($id),
            ],
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
        $username = $this->input('username');

        $this->merge([
            'aktif' => $this->boolean('aktif'),
            // Dirapikan sebelum diperiksa, bukan sesudah. Form mengirim string
            // kosong untuk isian yang tidak diisi, dan string kosong akan lolos
            // `nullable` lalu jatuh di aturan bentuk dengan pesan yang tidak
            // menjelaskan apa pun. Diubah menjadi null di sini supaya jalur
            // "kosongkan, biar sistem yang membuatkan" benar-benar terbuka saat
            // menambah akun.
            'username' => is_string($username) && trim($username) !== ''
                ? Str::lower(trim($username))
                : null,
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
            'username.required' => 'Username adalah kredensial masuk, jadi tidak boleh dikosongkan pada akun yang sudah ada.',
            'username.regex' => 'Username hanya boleh berisi huruf, angka, titik, garis bawah, dan garis hubung, serta diawali huruf atau angka.',
            'username.unique' => 'Username ini sudah dipakai akun lain, termasuk akun yang sudah dinonaktifkan.',
        ];
    }
}
