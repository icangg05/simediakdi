<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mencabut tipe sumber google_news beserta ketiga sumbernya.
 *
 * robots.txt milik news.google.com melarang pengambilan `/rss/search`, jadi
 * ketiga sumber ini gagal pada setiap kali jalan sejak dipasang dan tidak
 * pernah menghasilkan satu artikel pun. Yang dihasilkan hanya tiga baris log
 * gagal tiap jam, yang membuat halaman Log crawl selalu terlihat merah untuk
 * kerusakan yang tidak bisa diperbaiki dari sisi kita.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Log ikut dibuang. Kalau dibiarkan, halaman Log crawl tetap penuh
        // baris gagal milik sumber yang sudah tidak ada, dan filternya menunjuk
        // sumber yang tidak bisa dipilih lagi.
        DB::table('log_crawl')
            ->whereIn('sumber_feed_id', DB::table('sumber_feed')->where('tipe', 'google_news')->pluck('id'))
            ->delete();

        DB::table('sumber_feed')->where('tipe', 'google_news')->delete();

        DB::statement('ALTER TABLE sumber_feed DROP CONSTRAINT IF EXISTS chk_sumber_feed_tipe');
        DB::statement("ALTER TABLE sumber_feed ADD CONSTRAINT chk_sumber_feed_tipe
            CHECK (tipe IN ('rss','scrape'))");
    }

    public function down(): void
    {
        // Hanya batasannya yang dikembalikan. Barisnya tidak, karena memang
        // tidak ada yang bisa dipulihkan: sumbernya tidak pernah berhasil.
        DB::statement('ALTER TABLE sumber_feed DROP CONSTRAINT IF EXISTS chk_sumber_feed_tipe');
        DB::statement("ALTER TABLE sumber_feed ADD CONSTRAINT chk_sumber_feed_tipe
            CHECK (tipe IN ('rss','scrape','google_news'))");
    }
};
