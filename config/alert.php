<?php

return [
    // Kredensial Telegram tidak ada di sini. Token bot dan chat ID hanya
    // disimpan di tabel `pengaturan_alert` dan disetel dari halaman Pengaturan
    // sistem. Dua sumber untuk satu nilai berarti dua tempat yang bisa berbeda,
    // dan yang berbeda diam-diam adalah kredensial yang membuat alert berhenti
    // tanpa ada yang tahu sebabnya.

    // Berapa jam ke belakang aturan `sumber_mati` menganggap sebuah feed masih
    // hidup. Feed media daerah wajar diam semalaman, jadi ambangnya bukan
    // hitungan menit.
    'sumber_mati_jam' => (int) env('ALERT_SUMBER_MATI_JAM', 24),
];
