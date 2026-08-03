<?php

return [
    // Layanan tangkapan layar (dokumen 03 tabel pemuatan, F-52). Sama seperti
    // layanan NLP: diikat ke loopback, tanpa autentikasi selain binding itu.
    'base_url' => env('ARSIP_BASE_URL', 'http://127.0.0.1:8002'),

    // Render halaman berat bisa menyentuh 30 detik. Job berjalan di antrean,
    // jadi menunggu tidak menahan siapa pun.
    'timeout' => (int) env('ARSIP_TIMEOUT', 45),

    // Disk `local` berada di luar public/: bukti pemuatan menentukan pembayaran
    // kontrak dan tidak boleh terbuka dengan menebak URL (dokumen 06 bagian 7).
    'disk' => 'local',
    'folder' => 'arsip-pemuatan',
];
