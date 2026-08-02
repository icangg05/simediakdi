<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tumbuh cepat, nilainya menurun tajam setelah beberapa minggu.
        // Disimpan 90 hari lalu dihapus lewat scheduled job.
        Schema::create('log_crawl', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sumber_feed_id')->constrained('sumber_feed')->cascadeOnDelete();
            $table->timestampTz('dimulai_at');
            $table->timestampTz('selesai_at')->nullable();
            $table->integer('jumlah_ditemukan')->default(0);
            $table->integer('jumlah_baru')->default(0);
            $table->integer('jumlah_salinan')->default(0);
            $table->string('status', 20);
            $table->text('pesan')->nullable();
        });

        DB::statement("ALTER TABLE log_crawl ADD CONSTRAINT chk_log_crawl_status
            CHECK (status IN ('sukses','sebagian','gagal'))");
        DB::statement('CREATE INDEX idx_log_crawl_sumber_waktu ON log_crawl (sumber_feed_id, dimulai_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('log_crawl');
    }
};
