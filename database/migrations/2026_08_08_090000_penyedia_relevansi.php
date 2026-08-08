<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Siapa yang menilai relevansi artikel masuk: Gemini atau IndoBERT.
 *
 * Menumpang di `pengaturan_ai`, bukan tabel baru. Tabel itu memang sudah satu
 * baris berisi pengaturan klasifikasi yang disetel dari layar, dan penyedia
 * relevansi adalah pengaturan klasifikasi. Tabel sendiri hanya akan menambah
 * satu kueri untuk satu nilai.
 *
 * Bawaannya gemini. Instalasi yang sudah berjalan tidak boleh berpindah penilai
 * hanya karena migration dijalankan, dan model IndoBERT belum tentu sudah
 * dilatih di setiap lingkungan.
 *
 * Constraint-nya menyalin pola `analisis_sentimen.provider`, yang menerima dua
 * nilai yang sama persis. Kalau suatu hari ada penyedia ketiga, keduanya harus
 * berubah bersamaan, dan constraint yang menolak lebih baik daripada kolom
 * pengaturan berisi nama penyedia yang tidak pernah bisa tersimpan di hasilnya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengaturan_ai', function (Blueprint $table) {
            $table->string('penyedia_relevansi', 20)->default('gemini')->after('model');
        });

        DB::statement("ALTER TABLE pengaturan_ai ADD CONSTRAINT chk_pengaturan_penyedia_relevansi
            CHECK (penyedia_relevansi IN ('gemini','indobert'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pengaturan_ai DROP CONSTRAINT chk_pengaturan_penyedia_relevansi');

        Schema::table('pengaturan_ai', function (Blueprint $table) {
            $table->dropColumn('penyedia_relevansi');
        });
    }
};
