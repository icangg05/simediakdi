<?php

/*
 * Satu-satunya definisi apa yang dipantau SIMAK.
 *
 * Dulu ini berupa tabel `konteks_pantauan` dengan halaman CRUD tersendiri,
 * dirancang untuk memantau beberapa subjek sekaligus. Selama sistem berjalan
 * isinya tidak pernah lebih dari satu baris, dan barisnya tidak pernah berubah.
 * Yang tersisa hanyalah biayanya: kolom `konteks_pantauan_id` di empat tabel,
 * parameter `konteks` di setiap URL eksekutif, dan dropdown pemilih yang berisi
 * satu pilihan di lima halaman.
 *
 * Sekarang ia berupa berkas ini. Mengubahnya melewati deploy dan tercatat di
 * git, sama seperti ambang dan kunci API. Kalau suatu hari benar-benar perlu
 * memantau subjek kedua, tabelnya dibangun kembali dari riwayat git, dan saat
 * itu kebutuhannya sudah nyata alih-alih diperkirakan.
 */

return [
    'nama' => env('PANTAUAN_NAMA', 'Pemerintah Kota Kendari'),

    /*
     * Dipakai penyaring feed untuk memutuskan artikel mana yang layak disimpan,
     * dan ikut disertakan ke prompt Gemini sebagai penunjuk subjek.
     *
     * Bukan daftar yang boleh dipanjangkan sembarangan. Setiap kata di sini
     * melonggarkan saringan, dan kata yang terlalu umum seperti "kendari" saja
     * akan meloloskan seluruh berita daerah.
     */
    'kata_kunci' => [
        'pemkot kendari',
        'pemerintah kota kendari',
        'kota kendari',
        'dinas',
        'opd',
        'apbd kendari',
        'sekda kendari',
        'diskominfo kendari',
    ],
];
