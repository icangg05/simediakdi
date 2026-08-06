<?php

/*
 * Konfigurasi Laravel AI SDK untuk SIMEDIA. Dokumen 13 bagian 5.
 *
 * Berkas ini digabung di atas config bawaan paket dengan array_merge dangkal,
 * jadi kunci `providers` di sini menggantikan seluruh daftar provider bawaan.
 * Itu disengaja. SIMEDIA hanya memanggil Gemini, dan daftar provider yang tidak
 * pernah dipakai hanya menambah kunci environment yang harus dijelaskan.
 */

return [
    'default' => 'gemini',

    'providers' => [
        'gemini' => [
            'driver' => 'gemini',

            /*
             * Sengaja kosong. Kunci tersimpan terenkripsi di tabel
             * `kunci_gemini`, dan App\Services\Ai\RotasiKunciGemini mengisi
             * nilai ini saat runtime dengan kunci yang kuotanya masih ada.
             *
             * Jangan dikembalikan menjadi env(). Kunci di dua tempat berarti
             * suatu hari keduanya berbeda, dan yang kalah tetap terbaca sebagai
             * kunci yang berlaku oleh siapa pun yang membuka `.env`.
             */
            'key' => null,

            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),

            /*
             * Hanya dipakai Laravel AI SDK sebagai model bawaan provider.
             * Model yang benar-benar dipanggil dibaca dari `pengaturan_ai` dan
             * dikirim eksplisit pada setiap permintaan, lihat
             * App\Services\Ai\GeminiClassificationService.
             */
            'models' => [
                'text' => [
                    'default' => 'gemini-3.5-flash-lite',
                ],
            ],
        ],
    ],

    /*
     * Timeout satu panggilan klasifikasi.
     *
     * Ditaruh di config, bukan di atribut agent, karena atribut PHP hanya
     * menerima nilai konstan sehingga tidak bisa membaca environment.
     */
    'gemini_timeout' => (int) env('GEMINI_TIMEOUT', 60),

    /*
     * Batas jumlah kutipan bukti yang diterima. Dokumen 13 bagian 13.
     *
     * Batas bawah 1 memaksa model menunjuk kalimat, bukan menyimpulkan tanpa
     * dasar. Batas atas 3 menahan kebiasaan menyalin separuh artikel lalu
     * menyebutnya bukti, yang membuat validasi selalu lolos dan kehilangan
     * gunanya.
     */
    'bukti' => [
        'minimal' => 1,
        'maksimal' => 3,
    ],

    /*
     * Batas huruf isi artikel yang dikirim ke Gemini.
     *
     * Berbeda jauh dari batas IndoBERT yang 1.200 huruf, karena Gemini tidak
     * dibatasi jendela 256 token dan justru butuh isi utuh agar kutipan
     * buktinya bisa diverifikasi. Artikel berita Kendari hampir semuanya di
     * bawah angka ini.
     *
     * ponytail: potong lurus di 12.000 huruf, ganti dengan pemilihan paragraf
     * terkait kalau ada media yang rutin menerbitkan artikel lebih panjang.
     */
    'maks_huruf_isi' => (int) env('AI_MAKS_HURUF_ISI', 12000),
];
