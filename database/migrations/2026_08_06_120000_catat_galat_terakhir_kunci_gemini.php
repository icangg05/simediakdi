<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menyimpan galat terakhir per kunci Gemini.
 *
 * Sebelumnya galat hanya masuk log aplikasi, dan log tidak menyebutkan kunci
 * mana yang menolak. Dengan tiga kunci, satu kunci yang salah ketik terbaca
 * sebagai "klasifikasi kadang gagal" tanpa satu pun petunjuk kunci mana yang
 * harus diganti.
 *
 * `alasan_limit` yang sudah ada tidak cukup. Ia hanya menyimpan kategori kuota
 * dan hanya terisi saat kena limit, sedangkan kunci yang ditolak karena salah
 * ketik atau kehabisan izin proyek tidak pernah menyentuh kolom itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kunci_gemini', function (Blueprint $tabel) {
            $tabel->text('galat_terakhir')->nullable();
            $tabel->timestampTz('galat_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kunci_gemini', function (Blueprint $tabel) {
            $tabel->dropColumn(['galat_terakhir', 'galat_at']);
        });
    }
};
