<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memindahkan penghitung pemakaian kuota harian dari cache ke barisnya sendiri.
 *
 * Sebelumnya angkanya hidup di Redis dengan kunci `gemini:rpd:{id}:{tanggal}`.
 * Cache adalah tempat yang salah untuk angka ini. Redis yang dibersihkan,
 * kontainer yang dijatuhkan, atau `cache:clear` yang dijalankan saat deploy
 * mengembalikan hitungannya ke nol, dan sejak detik itu halaman Pengaturan
 * melaporkan kuota yang masih utuh untuk kunci yang sebenarnya tinggal
 * beberapa permintaan lagi. Penjaga kuota membaca angka yang sama, jadi yang
 * rusak bukan cuma tampilannya: sistem melepas permintaan yang sudah pasti
 * dijawab 429, dan Google menghitung permintaan yang ditolaknya.
 *
 * `rpd_hari` menyimpan tanggal kalender waktu Pasifik yang sedang dihitung,
 * bukan tanggal lokal. Google memulangkan jatah harian pada pergantian hari di
 * zona itu, dan tanggal inilah yang membedakan hitungan hari ini dari sisa
 * hitungan kemarin tanpa perlu pekerjaan pembersih yang berjalan tengah malam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kunci_gemini', function (Blueprint $tabel) {
            $tabel->unsignedInteger('rpd_terpakai')->default(0);
            $tabel->date('rpd_hari')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kunci_gemini', function (Blueprint $tabel) {
            $tabel->dropColumn(['rpd_terpakai', 'rpd_hari']);
        });
    }
};
