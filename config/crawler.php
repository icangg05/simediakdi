<?php

return [
    'user_agent' => env('CRAWL_USER_AGENT', 'SimediaKendariBot/1.0 (+https://simedia.kendarikota.go.id/bot)'),

    'timeout' => (int) env('CRAWL_TIMEOUT', 20),

    // Jeda antar permintaan ke domain yang sama. Bukan formalitas: media lokal
    // sering memakai hosting kecil, dan crawler agresif akan diblokir atau
    // memicu keluhan ke Diskominfo.
    'delay_ms' => (int) env('CRAWL_DELAY_MS', 1500),

    // Satu situs bermasalah tidak boleh menghabiskan memori worker.
    'maks_unduh_byte' => 5 * 1024 * 1024,

    // F-07: sumber dinonaktifkan otomatis setelah gagal berturut-turut.
    'maks_gagal_berturut' => 5,

    'wordpress' => [
        // Jalur cepat lewat WP REST API. Matikan lewat .env kalau perlu
        // membandingkan hasilnya dengan Readability tanpa deploy ulang.
        'aktif' => (bool) env('CRAWL_WORDPRESS_API', true),
    ],

    'artikel' => [
        // Di bawah ini biasanya hanya teaser, ditandai untuk audit.
        'minimal_kata' => 80,
    ],

];
