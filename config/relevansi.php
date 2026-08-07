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
];
