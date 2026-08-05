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

    'dedup' => [
        // Dokumen 02 memberi nilai awal 4. Pengukuran pada teks berita
        // berbahasa Indonesia sepanjang 50-70 kata menunjukkan angka itu
        // terlalu ketat: satu rilis yang dimuat ulang dengan satu paragraf
        // tambahan berjarak 8-10 bit, sedangkan dua berita yang benar-benar
        // berbeda berjarak 30-34 bit meski kosakatanya nyaris sama. 12 duduk
        // di tengah celah itu. Kalibrasi ulang di sprint 3 dengan 100 pasangan
        // manual sesuai dokumen 07.
        'ambang_simhash' => (int) env('DEDUP_AMBANG_SIMHASH', 12),
        // Tanpa batas ini, berita banjir tahun lalu bisa dianggap salinan
        // berita banjir hari ini.
        'jendela_hari' => 7,
    ],

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
