<?php

return [
    /*
     * Direktori dataset hasil ekspor dan artefak model.
     *
     * Di luar public/. Bobot model bukan berkas yang layak diambil siapa pun
     * yang menebak URL-nya, dan sekali bisa diunduh publik tidak ada cara
     * menariknya kembali. Dokumen 02 bagian struktur folder.
     */
    'dataset_path' => env('RELEVANSI_DATASET_PATH', storage_path('app/private/relevance-datasets')),
    'artefak_path' => env('RELEVANSI_ARTEFAK_PATH', storage_path('app/private/relevance-models')),

    /*
     * Rahasia bersama untuk kelompok endpoint /relevancy/*.
     *
     * Binding ke 127.0.0.1 sudah menutup sebagian besar risiko, tetapi endpoint
     * yang menerima path direktori lalu menjalankan proses panjang pantas
     * mendapat lapisan kedua.
     */
    'training_secret' => env('RELEVANSI_TRAINING_SECRET', ''),

    /*
     * Timeout khusus. Memulai pelatihan mengembalikan respons segera karena
     * pekerjaannya jalan di latar, jadi yang lama justru ekspor dan pemuatan
     * model di sisi Python.
     */
    'training_timeout' => (int) env('RELEVANSI_TRAINING_TIMEOUT', 300),

    /*
     * Preset konfigurasi pelatihan.
     *
     * Berbeda dari default dokumen 10 bagian 10.2, dan sengaja. Dokumen itu
     * ditulis dengan asumsi IndoBERT base; checkpoint yang dipakai ternyata
     * large, 24 layer dan hidden 1024. Panjang 512 dengan batch 8 menuntut
     * sekitar 10 GB saat melatih, sedangkan input kita sudah berupa jendela
     * konteks terfokus sehingga 256 token hampir tidak kehilangan apa pun.
     *
     * Ini nilai awal form, bukan hardcode. Nilai final tiap eksperimen
     * tersimpan di kolom `configuration` milik pelatihan_model_relevansi.
     */
    'preset' => [
        'base_model' => env('RELEVANSI_BASE_MODEL', 'apriandito/indobert-relevancy-classifier'),
        'epoch' => 3,
        'batch_size' => 4,
        'gradient_accumulation' => 4,
        'learning_rate' => 1e-5,
        'weight_decay' => 0.01,
        'warmup_ratio' => 0.1,
        'max_length' => 256,
        'class_weighting' => true,
        'early_stopping_patience' => 2,
        'random_seed' => 42,
        'metric_utama' => 'f1_relevan',
    ],
];
