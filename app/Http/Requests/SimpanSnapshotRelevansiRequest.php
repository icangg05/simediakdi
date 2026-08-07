<?php

namespace App\Http\Requests;

use App\Services\ModelRelevansi\KandidatDataset;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pemeriksaan komposisi dan pembagian snapshot dataset.
 *
 * Aturan angkanya ditegakkan di sini, bukan hanya di layar. Form di browser
 * memang sudah menahan angka yang salah, tetapi permintaan yang sama bisa
 * dikirim langsung, dan snapshot yang lolos dengan komposisi mustahil akan
 * meledak jauh kemudian di dalam job pelatihan dengan pesan yang tidak
 * menunjuk ke sebabnya.
 */
class SimpanSnapshotRelevansiRequest extends FormRequest
{
    /**
     * Batas bawah yang membuat pelatihan masih ada artinya.
     *
     * Lima puluh baris bukan angka statistik, melainkan batas kewarasan. Di
     * bawah itu bagian test hanya berisi beberapa artikel, dan satu tebakan
     * yang meleset menggeser akurasi belasan persen.
     */
    private const MIN_TOTAL = 50;

    /** Tiap label wajib punya baris sebanyak ini di dalam snapshot. */
    private const MIN_PER_LABEL = 10;

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:150', 'unique:snapshot_dataset_relevansi,nama'],
            'deskripsi' => ['nullable', 'string', 'max:1000'],
            'jumlah_total' => ['required', 'integer', 'min:'.self::MIN_TOTAL],

            // Nol persen pada salah satu label menghasilkan dataset satu kelas,
            // dan model satu kelas selalu menjawab sama sambil terlihat akurat.
            'persen_relevan' => ['required', 'integer', 'min:10', 'max:90'],
            'persen_tidak_relevan' => ['required', 'integer', 'min:10', 'max:90'],

            'persen_train' => ['required', 'integer', 'min:50', 'max:90'],
            // Validation dipakai memilih epoch terbaik dan menghentikan
            // pelatihan lebih awal. Kosong berarti kedua mekanisme itu memilih
            // secara buta.
            'persen_validation' => ['required', 'integer', 'min:5', 'max:40'],
            'persen_test' => ['required', 'integer', 'min:5', 'max:40'],

            'random_seed' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->periksaJumlah($validator);
            $this->periksaKetersediaan($validator);
        });
    }

    private function periksaJumlah(Validator $validator): void
    {
        if ((int) $this->persen_relevan + (int) $this->persen_tidak_relevan !== 100) {
            $validator->errors()->add(
                'persen_relevan',
                'Komposisi label harus berjumlah tepat 100 persen.'
            );
        }

        if ((int) $this->persen_train + (int) $this->persen_validation + (int) $this->persen_test !== 100) {
            $validator->errors()->add(
                'persen_train',
                'Pembagian training, validation, dan testing harus berjumlah tepat 100 persen.'
            );
        }
    }

    private function periksaKetersediaan(Validator $validator): void
    {
        $tersedia = app(KandidatDataset::class)->ringkasan();

        $total = (int) $this->jumlah_total;

        $diminta = [
            'relevan' => (int) round($total * (int) $this->persen_relevan / 100),
            'tidak_relevan' => 0,
        ];
        $diminta['tidak_relevan'] = $total - $diminta['relevan'];

        foreach (['relevan' => 'Relevan', 'tidak_relevan' => 'Tidak Relevan'] as $label => $sebutan) {
            if ($diminta[$label] > $tersedia[$label]) {
                $validator->errors()->add('jumlah_total', sprintf(
                    'Komposisi ini butuh %d artikel %s, sedangkan kandidat yang tersedia hanya %d. '
                    .'Turunkan jumlah total atau ubah persentasenya.',
                    $diminta[$label],
                    $sebutan,
                    $tersedia[$label],
                ));

                continue;
            }

            if ($diminta[$label] < self::MIN_PER_LABEL) {
                $validator->errors()->add('jumlah_total', sprintf(
                    'Komposisi ini hanya mengambil %d artikel %s. Setiap label butuh sekurangnya %d artikel '
                    .'agar bagian training, validation, dan testing sama-sama kebagian.',
                    $diminta[$label],
                    $sebutan,
                    self::MIN_PER_LABEL,
                ));
            }
        }
    }

    public function messages(): array
    {
        return [
            'nama.unique' => 'Sudah ada snapshot dengan nama ini. Pakai nama lain supaya keduanya bisa dibedakan di daftar pelatihan.',
            'jumlah_total.min' => 'Snapshot berisi kurang dari '.self::MIN_TOTAL.' artikel terlalu kecil untuk menghasilkan angka evaluasi yang berarti.',
            'persen_relevan.min' => 'Setiap label harus mendapat sekurangnya 10 persen, agar model belajar mengenali keduanya.',
            'persen_relevan.max' => 'Setiap label harus mendapat sekurangnya 10 persen, agar model belajar mengenali keduanya.',
            'persen_tidak_relevan.min' => 'Setiap label harus mendapat sekurangnya 10 persen, agar model belajar mengenali keduanya.',
            'persen_tidak_relevan.max' => 'Setiap label harus mendapat sekurangnya 10 persen, agar model belajar mengenali keduanya.',
            'persen_train.min' => 'Bagian training sekurangnya 50 persen. Di bawah itu model tidak punya cukup contoh untuk belajar.',
            'persen_validation.min' => 'Bagian validation sekurangnya 5 persen, karena bagian ini yang dipakai memilih epoch terbaik.',
            'persen_test.min' => 'Bagian testing sekurangnya 5 persen, karena angka akhir model dihitung dari bagian ini.',
        ];
    }
}
