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
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),

            /*
             * Nama model ditulis sekali di sini, bukan di job atau controller.
             * Setiap prediksi menyimpan nilai ini, jadi hasil dari dua model
             * berbeda tidak pernah tercampur tanpa bisa dibedakan.
             */
            'models' => [
                'text' => [
                    'default' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
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
     * Versi prompt yang sedang berlaku, sekaligus nama berkas di
     * resources/prompts. Setiap prediksi menyimpannya supaya perbandingan
     * benchmark antar versi prompt tidak perlu menebak isi prompt saat itu.
     */
    'prompt' => [
        'relevansi' => env('AI_RELEVANCE_PROMPT_VERSION', 'relevance-v1'),
        'sentimen' => env('AI_SENTIMENT_PROMPT_VERSION', 'sentiment-v1'),
    ],

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
