<?php

return [
    'base_url' => env('NLP_BASE_URL', 'http://127.0.0.1:8001'),

    // Layanan lambat harus membuat job di-retry, bukan gagal permanen.
    'timeout' => (int) env('NLP_TIMEOUT', 120),

    // Sesuai batas di sisi layanan. Melebihinya ditolak, bukan dipotong diam-diam.
    'batch_pasangan' => (int) env('NLP_BATCH_SIZE', 16),
    'batch_embed' => 32,

    'dimensi_embedding' => 384,

    // Ambang ditaruh di environment, bukan hardcode, karena akan disetel setelah
    // gold set jadi dan tidak seorang pun mau deploy ulang untuk satu angka.
    //
    // Dokumen 02 memberi nilai awal 0,60. Pengukuran pada 24 pasangan artikel
    // nyata menunjukkan sebaran keyakinan model ini bimodal dan tanpa nilai
    // tengah: 20 hasil di atas 0,998, lalu kosong sama sekali, lalu 3 hasil di
    // 0,60–0,67. Ambang 0,60 jatuh persis di tepi bawah kelompok yang ragu,
    // jadi `perlu_review` tidak pernah menyala — dan F-12 ikut mati diam-diam
    // padahal ada di daftar yang tidak boleh dipangkas.
    //
    // 0,90 berada di tengah jurang kosong itu. INI MASIH HIPOTESIS: yang diukur
    // baru sebaran keyakinan, bukan titik di mana model mulai salah. Setel
    // ulang dari gold set begitu 400 baris selesai dilabeli (dokumen 07).
    'ambang' => [
        'sentimen' => (float) env('SENTIMEN_AMBANG_KEYAKINAN', 0.90),
        'relevansi' => (float) env('RELEVANSI_AMBANG_KEYAKINAN', 0.55),
    ],
];
