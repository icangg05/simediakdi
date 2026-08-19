<?php

/*
 * Konfigurasi Laravel AI SDK untuk SIMAK. Dokumen 13 bagian 5.
 *
 * Berkas ini digabung di atas config bawaan paket dengan array_merge dangkal,
 * jadi kunci `providers` di sini menggantikan seluruh daftar provider bawaan.
 * Itu disengaja. SIMAK hanya memanggil Gemini, dan daftar provider yang tidak
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
     * Batas pemakaian per kunci, dijaga sendiri sebelum Google menolak.
     *
     * Rotasi kunci sudah menangani 429, tetapi baru setelah 429 itu terjadi.
     * Menabrak batas berulang kali bukan sekadar boros: Google menghitung
     * permintaan yang ditolak sebagai permintaan, jadi kuota harian bisa habis
     * tanpa satu pun artikel selesai dinilai.
     *
     * Angkanya mengikuti free tier gemini-3.5-flash-lite: 15 permintaan per
     * menit dan 500 per hari, per proyek per model. Ubah lewat env kalau
     * kuncinya naik ke tier berbayar.
     *
     * TPM sengaja tidak dijaga. Menghitungnya butuh jumlah token setiap
     * permintaan sebelum dikirim, sedangkan pemakaian nyata masih jauh di bawah
     * batas: sekitar 9K dari 250K per menit.
     *
     * RPM dijaga dengan jendela geser 60 detik, bukan menit kalender. Menit
     * kalender pernah dipakai dan celahnya nyata: lima belas permintaan pada
     * detik ke-59 lalu lima belas lagi pada detik ke-01 semuanya sah menurut
     * hitungan lokal, padahal Google melihat tiga puluh permintaan dalam dua
     * detik. Uji jalan antrean pertama mengirim 109 permintaan untuk 19 artikel
     * gara-gara itu.
     */
    'batas_kunci' => [
        'rpm' => (int) env('GEMINI_BATAS_RPM', 15),
        'rpd' => (int) env('GEMINI_BATAS_RPD', 500),
    ],

    /*
     * Antrean klasifikasi otomatis.
     *
     * `gantung` adalah jumlah pekerjaan yang boleh berada di Redis sekaligus.
     * Job menunda dirinya sendiri saat kuota habis, jadi melepas seluruh
     * antrean sekaligus tidak merusak apa pun. Yang rusak adalah kejelasannya:
     * ribuan pekerjaan yang saling menunda membuat halaman pemantauan tidak
     * bisa lagi membedakan yang sedang dikerjakan dari yang sekadar sudah
     * dilempar.
     *
     * Duapuluh kira-kira sepadan dengan satu menit kerja. Dua kunci pada free
     * tier memberi 30 permintaan per menit, dan satu artikel memakan satu
     * sampai dua permintaan tergantung relevan atau tidaknya.
     */
    'antrean' => [
        'gantung' => (int) env('GEMINI_ANTREAN_GANTUNG', 20),

        /*
         * Jarak antar artikel saat relevansi dikerjakan IndoBERT.
         *
         * Jauh lebih rapat karena penyaringnya tidak lagi memakai kuota siapa
         * pun. Inferensi berjalan di server sendiri, dan artikel yang ditolak
         * selesai tanpa satu pun permintaan ke Google.
         *
         * Ini murni soal beban CPU layanan inferensi, bukan soal kuota. Artikel
         * yang lolos tetap memilih kunci Gemini yang sudah menganggur 15 detik,
         * sama dengan jalur klasifikasi manual.
         */
        'jeda_detik_indobert' => (int) env('RELEVANSI_ANTREAN_JEDA', 5),
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
