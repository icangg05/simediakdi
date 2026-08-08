<?php

return [
    // Layanan pelatihan dan inferensi IndoBERT. Sama seperti layanan arsip:
    // diikat ke loopback, tanpa autentikasi selain binding itu.
    'base_url' => env('RELEVANSI_BASE_URL', 'http://127.0.0.1:8001'),

    // Pengiriman dataset. Satu snapshot seribuan artikel beserta teksnya bisa
    // beberapa megabita, dan layanan menjawab 202 begitu pelatihan dilempar ke
    // thread latar, jadi menunggu di sini tidak selama kelihatannya.
    'timeout_kirim' => (int) env('RELEVANSI_TIMEOUT_KIRIM', 180),

    // Tarikan status dan pembatalan. Keduanya membaca dict di memori.
    'timeout_status' => (int) env('RELEVANSI_TIMEOUT_STATUS', 15),

    // Inferensi satu teks. Pemuatan model pertama kali memakan belasan detik,
    // dan tombol Uji Model menunggunya di dalam permintaan HTTP.
    'timeout_prediksi' => (int) env('RELEVANSI_TIMEOUT_PREDIKSI', 90),

    // Jeda antar tarikan status oleh job pelatihan. Layar menariknya sendiri
    // tiap tiga detik, jadi lebih rapat dari ini hanya menambah kueri tanpa
    // menambah satu pun angka baru yang terlihat.
    'jeda_polling' => (int) env('RELEVANSI_JEDA_POLLING', 5),

    // Di bawah ambang ini keputusan IndoBERT tidak dipakai, dan artikelnya
    // diteruskan ke Menunggu review supaya manusia yang memutuskan.
    //
    // Layanan Python menghitung confidence sebagai jarak dari keraguan,
    // `abs(p - 0.5) * 2`, jadi 0,4 berarti probabilitas relevan antara 0,30
    // dan 0,70 dianggap ragu.
    //
    // Env, bukan konstanta. Angka yang benar baru terlihat setelah beberapa
    // ratus keputusan sungguhan dibandingkan dengan koreksi admin, dan sampai
    // itu terjadi angkanya akan disetel berkali-kali. Mulai tinggi lalu
    // turunkan lebih aman daripada sebaliknya: model yang terlalu berani
    // membuang berita relevan tanpa ada yang meninjau.
    'ambang_ragu' => (float) env('RELEVANSI_AMBANG_RAGU', 0.4),
];
