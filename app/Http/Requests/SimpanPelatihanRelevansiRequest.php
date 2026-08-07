<?php

namespace App\Http\Requests;

use App\Models\SnapshotDatasetRelevansi;
use App\Services\ModelRelevansi\LayananRelevansi;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pemeriksaan konfigurasi pelatihan.
 *
 * Batas angkanya dibaca dari LayananRelevansi::BATAS, konstanta yang sama yang
 * dikirim ke layar untuk mengisi atribut min dan max di form. Menuliskan
 * angkanya dua kali berarti suatu hari form mengizinkan sesuatu yang ditolak
 * server, dan pengguna melihat penolakan tanpa tahu sebabnya.
 */
class SimpanPelatihanRelevansiRequest extends FormRequest
{
    public function rules(): array
    {
        $batas = LayananRelevansi::BATAS;

        return [
            'nama' => ['required', 'string', 'max:150', 'unique:pelatihan_model_relevansi,nama'],
            'snapshot_dataset_relevansi_id' => ['required', 'integer', 'exists:snapshot_dataset_relevansi,id'],
            'base_model' => ['required', Rule::in(array_keys(LayananRelevansi::BASE_MODEL))],

            'epoch' => ['required', 'integer', "min:{$batas['epoch']['min']}", "max:{$batas['epoch']['maks']}"],
            'batch_size' => ['required', 'integer', "min:{$batas['batch_size']['min']}", "max:{$batas['batch_size']['maks']}"],
            'learning_rate' => ['required', 'numeric', "min:{$batas['learning_rate']['min']}", "max:{$batas['learning_rate']['maks']}"],
            'max_seq_length' => ['required', 'integer', "min:{$batas['max_seq_length']['min']}", "max:{$batas['max_seq_length']['maks']}"],
            'seed' => ['nullable', 'integer', 'min:0', 'max:2147483647'],

            // Kosong berarti pelatihan berjalan sampai epoch terakhir.
            'early_stopping' => ['nullable', 'integer', "min:{$batas['early_stopping']['min']}", "max:{$batas['early_stopping']['maks']}"],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $snapshot = SnapshotDatasetRelevansi::find($this->snapshot_dataset_relevansi_id);

            if ($snapshot === null) {
                return;
            }

            if ($snapshot->status === 'arsip') {
                $validator->errors()->add(
                    'snapshot_dataset_relevansi_id',
                    'Snapshot ini sudah diarsipkan dan tidak bisa dipakai melatih model baru.'
                );
            }

            // Snapshot yang isinya hilang, misalnya karena baris item terhapus,
            // akan lolos ke antrean lalu gagal beberapa menit kemudian dengan
            // pesan yang tidak menyebut snapshot sama sekali.
            if ($snapshot->total_train < 1 || $snapshot->total_test < 1) {
                $validator->errors()->add(
                    'snapshot_dataset_relevansi_id',
                    'Snapshot ini tidak punya data training atau testing. Buat snapshot baru sebelum melatih.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'nama.unique' => 'Sudah ada pelatihan dengan nama ini. Beri nama berbeda agar hasilnya tidak tertukar di daftar.',
            'snapshot_dataset_relevansi_id.required' => 'Pilih snapshot dataset terlebih dahulu. Pelatihan tidak bisa dimulai tanpa dataset.',
            'snapshot_dataset_relevansi_id.exists' => 'Snapshot yang dipilih sudah tidak ada. Muat ulang halaman lalu pilih yang lain.',
        ];
    }
}
