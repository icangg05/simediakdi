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

        // Tidak berpengaruh pada model relevansi yang dipakai sekarang: diuji
        // dari 0,55 sampai 0,999 dan presisi hanya bergerak 47,4% ke 48,1%,
        // karena keyakinannya bermedian 1,000 baik saat benar maupun salah.
        // Dipertahankan karena disebut dokumen 02 dan akan berguna kalau
        // modelnya diganti. Yang benar-benar menaikkan presisi adalah
        // `minimal_sebutan` di bawah.
        'relevansi' => (float) env('RELEVANSI_AMBANG_KEYAKINAN', 0.55),
    ],

    // Berapa kali kata kunci konteks harus muncul di badan berita agar artikel
    // dianggap benar-benar membahasnya, kalau judulnya tidak menyebut sama
    // sekali. Diukur terhadap 254 label manusia dengan separuh data ditahan:
    // presisi naik 54,2% ke 80,0% sementara recall turun tipis 100% ke 92,3%.
    // Menaikkannya ke 4 justru memburuk pada data tahan — ukur ulang sebelum
    // mengubahnya.
    'minimal_sebutan' => (int) env('RELEVANSI_MINIMAL_SEBUTAN', 3),
];
