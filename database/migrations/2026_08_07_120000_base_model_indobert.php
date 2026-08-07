<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Melebarkan kolom base model untuk nama checkpoint Hugging Face.
 *
 * Enam puluh karakter cukup untuk `tfidf-unigram`, tetapi nama checkpoint
 * membawa nama organisasinya: `apriandito/indobert-relevancy-classifier` sudah
 * 42 karakter, dan checkpoint komunitas rutin melewati enam puluh. Kolom yang
 * kesempitan menolak baris dengan galat Postgres yang menyebut panjang varchar,
 * bukan menyebut model mana yang tidak muat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pelatihan_model_relevansi', function (Blueprint $table) {
            $table->string('base_model', 200)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pelatihan_model_relevansi', function (Blueprint $table) {
            $table->string('base_model', 60)->change();
        });
    }
};
