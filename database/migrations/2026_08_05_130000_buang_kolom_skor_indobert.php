<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuang kolom skor sentimen milik IndoBERT.
 *
 * Keempatnya berhenti terisi begitu Gemini menjadi satu-satunya penilai. Gemini
 * tidak mengeluarkan distribusi probabilitas atas tiga kelas, ia memilih satu
 * label dan menunjuk kalimat yang mendasarinya.
 *
 * Membiarkannya lebih berbahaya daripada membuangnya. Kolom yang berisi angka
 * lama dan tidak pernah bertambah akan tetap terbaca sebagai skor yang masih
 * berlaku oleh kueri berikutnya yang ditulis, dan angka usang yang tampak wajar
 * tidak menimbulkan galat apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analisis_sentimen', function (Blueprint $table) {
            $table->dropColumn(['skor_negatif', 'skor_netral', 'skor_positif', 'keyakinan']);
        });
    }

    public function down(): void
    {
        Schema::table('analisis_sentimen', function (Blueprint $table) {
            $table->float('skor_negatif')->nullable()->after('label_model');
            $table->float('skor_netral')->nullable()->after('skor_negatif');
            $table->float('skor_positif')->nullable()->after('skor_netral');
            $table->float('keyakinan')->nullable()->after('skor_positif');
        });
    }
};
