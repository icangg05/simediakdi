<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimpanLaporanPemuatanRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kontrak_id' => ['required', 'integer', 'exists:kontrak,id'],
            'keterangan' => ['nullable', 'string', 'max:1000'],

            'baris' => ['required', 'array', 'min:1', 'max:50'],
            'baris.*.url' => ['required', 'url:http,https', 'max:1000'],
            // Judul dan tanggal datang dari ekstraksi pada kasus normal, dan
            // dari isian media hanya saat ekstraksi gagal (F-51). Keduanya
            // tetap wajib di sini: baris tanpa judul tidak bisa diverifikasi
            // admin tanpa membuka tautannya satu per satu.
            'baris.*.judul' => ['required', 'string', 'max:500'],
            'baris.*.tanggal' => ['required', 'date', 'before_or_equal:today'],

            // Tangkapan layar unggahan media adalah pelengkap, bukan bukti
            // utama (dokumen 03 tabel pemuatan). Tipe diperiksa dari isi
            // berkasnya, bukan dari ekstensi (dokumen 06 bagian 7).
            'baris.*.bukti' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'baris.required' => 'Tidak ada tautan yang dikirim.',
            'baris.*.tanggal.before_or_equal' => 'Tanggal muat tidak boleh di masa depan.',
            'baris.*.judul.required' => 'Judul wajib diisi untuk tautan yang gagal dibaca otomatis.',
        ];
    }
}
