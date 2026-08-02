<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sumber_feed', function (Blueprint $table) {
            $table->id();
            // Null untuk sumber Google News yang lintas media.
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('nama', 150);
            $table->string('tipe', 20);
            $table->string('url', 500);
            $table->jsonb('selector')->nullable();
            $table->string('kata_kunci', 255)->nullable();
            $table->integer('interval_menit')->default(30);
            $table->boolean('aktif')->default(true);
            $table->timestampTz('dijalankan_terakhir_at')->nullable();
            $table->timestampTz('berhasil_terakhir_at')->nullable();
            $table->smallInteger('gagal_berturut')->default(0);
            $table->text('pesan_error_terakhir')->nullable();
            $table->timestamps();

            // Memilih sumber yang jatuh tempo.
            $table->index(['aktif', 'dijalankan_terakhir_at']);
        });

        DB::statement("ALTER TABLE sumber_feed ADD CONSTRAINT chk_sumber_feed_tipe
            CHECK (tipe IN ('rss','scrape','google_news'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sumber_feed');
    }
};
