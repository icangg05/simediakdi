<?php

return [
    // Alamat kontak sengaja berhenti di domain, tanpa path `/bot` seperti
    // sebelumnya. WAF sibernas.id menolak permintaan dengan 403 hanya karena
    // path itu ada di dalam User-Agent, dan situs itu jadi satu-satunya media
    // yang tidak bisa ditarik sama sekali. Token produknya tetap
    // `SimakKendariBot`, jadi aturan robots.txt di 29 situs lain tidak
    // berubah artinya.
    'user_agent' => env('CRAWL_USER_AGENT', 'SimakKendariBot/1.0 (+https://simak.kendarikota.go.id)'),

    'timeout' => (int) env('CRAWL_TIMEOUT', 20),

    // Render Chromium jauh lebih lambat daripada satu GET. Halaman Nuxt harus
    // dimuat, dihidrasi, lalu menunggu permintaan XHR terakhir yang membawa
    // daftar beritanya.
    'render_timeout' => (int) env('CRAWL_RENDER_TIMEOUT', 60),

    // Jeda antar permintaan ke domain yang sama. Bukan formalitas: media lokal
    // sering memakai hosting kecil, dan crawler agresif akan diblokir atau
    // memicu keluhan ke Diskominfo.
    'delay_ms' => (int) env('CRAWL_DELAY_MS', 1500),

    // Satu situs bermasalah tidak boleh menghabiskan memori worker.
    'maks_unduh_byte' => 5 * 1024 * 1024,

    // Ambang "sumber ini perlu diperiksa", bukan lagi saklar mati.
    //
    // F-07 dulu menonaktifkan sumber di angka ini. Aturan itu dicabut karena
    // penyebab kegagalan hampir selalu di luar kendali kita dan sembuh sendiri,
    // sementara sumber yang mati diam-diam menghentikan berita satu media tanpa
    // ada yang menyadarinya. Sekarang angkanya hanya dipakai dashboard dan
    // halaman detail media untuk menandai sumber yang gagal berulang.
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
