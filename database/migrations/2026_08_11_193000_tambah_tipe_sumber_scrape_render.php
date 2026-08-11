<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menambah tipe sumber `scrape_render` untuk halaman indeks yang butuh JavaScript.
 *
 * tempo.co adalah aplikasi Nuxt. HTML yang dikirim servernya hanya berisi
 * kerangka halaman dan daftar pilihan filter, sedangkan daftar beritanya baru
 * dirakit di peramban sesudah hidrasi. Pengunduh biasa tidak menemukan satu
 * tautan artikel pun di sana, dan itu sebabnya Tempo tidak pernah menyumbang
 * artikel meski sumbernya jalan puluhan kali.
 *
 * Bukan kolom boolean tersendiri. Yang berbeda memang cara halamannya diambil,
 * dan itu persis yang dijawab kolom `tipe`. Selector, saringan kata kunci, dan
 * seluruh jalur sesudahnya sama saja dengan `scrape`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sumber_feed DROP CONSTRAINT IF EXISTS chk_sumber_feed_tipe');
        DB::statement("ALTER TABLE sumber_feed ADD CONSTRAINT chk_sumber_feed_tipe
            CHECK (tipe IN ('rss','scrape','scrape_render'))");
    }

    public function down(): void
    {
        // Sumbernya diturunkan ke `scrape` lebih dulu, bukan dihapus. Barisnya
        // masih menyimpan URL dan selector yang benar, dan batasan yang
        // dipasang sebelum barisnya bersih akan menolak seluruh migrasi.
        DB::table('sumber_feed')->where('tipe', 'scrape_render')->update(['tipe' => 'scrape', 'aktif' => false]);

        DB::statement('ALTER TABLE sumber_feed DROP CONSTRAINT IF EXISTS chk_sumber_feed_tipe');
        DB::statement("ALTER TABLE sumber_feed ADD CONSTRAINT chk_sumber_feed_tipe
            CHECK (tipe IN ('rss','scrape'))");
    }
};
