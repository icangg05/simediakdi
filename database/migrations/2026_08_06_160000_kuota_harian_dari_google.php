<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyimpan batas kuota harian yang disebutkan Google sendiri per kunci.
 *
 * Sebelumnya batas harian hanya angka tebakan di `config/ai.php`, disalin dari
 * halaman dokumentasi free tier. Angka itu meleset dengan dua cara. Google
 * mengubah jatah free tier tanpa pemberitahuan, dan kunci yang proyeknya sudah
 * berbayar punya jatah yang sama sekali berbeda. Keduanya berakhir sama:
 * penjaga kuota lokal menahan kunci yang sebenarnya masih punya jatah, atau
 * melepas permintaan yang sudah pasti ditolak.
 *
 * Sumbernya badan galat 429 dari Google, yang memuat `QuotaFailure` berisi
 * `quotaValue`. Itu satu-satunya tempat angka resminya bisa dibaca memakai
 * kunci API biasa: kuota proyek hanya terbuka lewat Cloud Quotas API yang
 * menuntut kredensial OAuth dan nomor proyek, dua hal yang tidak dimiliki
 * sistem ini.
 *
 * Karena itu kolomnya nullable. Kunci yang belum pernah kehabisan kuota belum
 * pernah diberitahu angkanya, dan sampai saat itu tiba nilai config yang
 * dipakai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kunci_gemini', function (Blueprint $tabel) {
            $tabel->unsignedInteger('rpd_google')->nullable();
            $tabel->timestampTz('rpd_google_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kunci_gemini', function (Blueprint $tabel) {
            $tabel->dropColumn(['rpd_google', 'rpd_google_at']);
        });
    }
};
