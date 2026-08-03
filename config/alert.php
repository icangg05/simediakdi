<?php

return [
    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN', ''),
        'chat_id' => env('TELEGRAM_CHAT_ID', ''),
    ],

    // Berapa jam ke belakang aturan `sumber_mati` menganggap sebuah feed masih
    // hidup. Feed media daerah wajar diam semalaman, jadi ambangnya bukan
    // hitungan menit.
    'sumber_mati_jam' => (int) env('ALERT_SUMBER_MATI_JAM', 24),
];
