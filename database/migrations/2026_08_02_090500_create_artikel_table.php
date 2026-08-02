<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artikel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('sumber_feed_id')->nullable()->constrained('sumber_feed')->nullOnDelete();
            $table->string('judul', 500);
            $table->string('url', 1000);
            $table->string('url_kanonik', 1000);
            $table->string('ringkasan', 600)->nullable();
            $table->text('isi')->nullable();
            $table->string('penulis', 150)->nullable();
            $table->integer('jumlah_kata')->nullable();
            $table->string('gambar_url', 1000)->nullable();
            // Dari feed. Bisa null atau salah, jangan diandalkan sendiri.
            $table->timestampTz('dipublikasikan_at')->nullable();
            // Ini yang dipakai untuk grafik harian.
            $table->timestampTz('diambil_at')->useCurrent();
            $table->char('hash_isi', 64)->nullable();
            $table->bigInteger('simhash')->nullable();
            $table->vector('embedding', 384)->nullable();
            $table->string('status_dedup', 20)->default('asli');
            $table->foreignId('artikel_induk_id')->nullable()->constrained('artikel')->nullOnDelete();
            $table->float('skor_kemiripan')->nullable();
            $table->string('status_proses', 20)->default('mentah');
            $table->text('pesan_gagal')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE artikel ADD CONSTRAINT chk_artikel_status_dedup
            CHECK (status_dedup IN ('asli','salinan'))");
        DB::statement("ALTER TABLE artikel ADD CONSTRAINT chk_artikel_status_proses
            CHECK (status_proses IN ('mentah','isi_diambil','dianalisis','selesai','gagal'))");
        // artikel_induk_id harus null saat status_dedup = 'asli'.
        DB::statement("ALTER TABLE artikel ADD CONSTRAINT chk_artikel_induk_sesuai_dedup CHECK (
            (status_dedup = 'asli' AND artikel_induk_id IS NULL) OR
            (status_dedup = 'salinan' AND artikel_induk_id IS NOT NULL)
        )");

        DB::statement('CREATE UNIQUE INDEX uq_artikel_url_kanonik ON artikel (url_kanonik)');
        DB::statement('CREATE INDEX idx_artikel_diambil ON artikel (diambil_at DESC)');
        DB::statement('CREATE INDEX idx_artikel_media_waktu ON artikel (media_id, diambil_at DESC)');
        // Partial: baris belum selesai selalu sedikit, worker menemukan pekerjaan
        // tanpa memindai seluruh tabel.
        DB::statement("CREATE INDEX idx_artikel_status_proses ON artikel (status_proses)
            WHERE status_proses <> 'selesai'");
        // Partial: hampir semua agregasi dashboard mengecualikan salinan.
        DB::statement("CREATE INDEX idx_artikel_asli ON artikel (diambil_at DESC)
            WHERE status_dedup = 'asli'");
        DB::statement('CREATE INDEX idx_artikel_judul_trgm ON artikel USING gin (judul gin_trgm_ops)');
        DB::statement('CREATE INDEX idx_artikel_embedding ON artikel USING hnsw (embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX idx_artikel_simhash ON artikel (simhash)');
    }

    public function down(): void
    {
        Schema::dropIfExists('artikel');
    }
};
