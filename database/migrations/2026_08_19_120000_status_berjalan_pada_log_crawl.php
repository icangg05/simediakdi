<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menambah status `berjalan` pada log crawl.
 *
 * Baris log dibuat sebelum pengambilan dimulai, dan sampai sekarang ia lahir
 * berstatus `gagal` sebagai keadaan aman: proses yang mati di tengah jalan
 * meninggalkan baris gagal, bukan baris sukses palsu. Niatnya benar, tapi
 * harganya terbaca di layar. Satu sumber memakan 7 sampai 16 detik, dan
 * selama itu halaman Log crawl menampilkan baris merah bertuliskan Gagal
 * untuk pekerjaan yang sebenarnya sedang berjalan normal. Admin yang menekan
 * tombol Crawl sekarang melihat deretan merah lebih dulu, lalu hijau beberapa
 * detik kemudian, dan menyimpulkan sistemnya bermasalah.
 *
 * Status baru memisahkan "sedang dikerjakan" dari "sudah dicoba dan gagal".
 * Sifat amannya tidak hilang: baris yang tertinggal di `berjalan` tanpa
 * `selesai_at` tetap menandakan proses yang berhenti di tengah, dan halaman
 * log menampilkannya sebagai Terhenti setelah lewat lima belas menit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE log_crawl DROP CONSTRAINT IF EXISTS chk_log_crawl_status');
        DB::statement("ALTER TABLE log_crawl ADD CONSTRAINT chk_log_crawl_status CHECK (status IN ('berjalan', 'sukses', 'sebagian', 'gagal'))");
    }

    public function down(): void
    {
        // Baris yang masih berjalan tidak punya padanan di daftar lama, dan
        // yang paling jujur untuknya adalah gagal, sama seperti sebelum
        // migration ini ada.
        DB::table('log_crawl')->where('status', 'berjalan')->update(['status' => 'gagal']);

        DB::statement('ALTER TABLE log_crawl DROP CONSTRAINT IF EXISTS chk_log_crawl_status');
        DB::statement("ALTER TABLE log_crawl ADD CONSTRAINT chk_log_crawl_status CHECK (status IN ('sukses', 'sebagian', 'gagal'))");
    }
};
