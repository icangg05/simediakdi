<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deduplikasi disederhanakan menjadi satu pertanyaan: URL ini sudah ada di
 * tabel artikel atau belum.
 *
 * Yang dicopot ada dua. Pertama deteksi salinan lewat hash isi dan simhash,
 * beserta seluruh kolom penyimpannya. Kedua tabel nisan `url_dibuang`, karena
 * penghapusan artikel sekarang benar-benar menghapus, tanpa sisa penanda.
 *
 * Akibatnya disengaja dan perlu diketahui: artikel yang dihapus admin bisa
 * masuk lagi pada crawl berikutnya, karena barisnya tidak ada lagi di tabel
 * artikel dan tidak ada tempat lain yang mengingatnya.
 *
 * Kolom `embedding` sengaja dibiarkan. Ia tidak dipakai deteksi salinan dan
 * isinya tidak bisa dibuat ulang tanpa layanan yang sudah dicabut.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Index dan constraint dilepas lebih dulu supaya kegagalan terjadi di
        // sini, bukan di tengah DROP COLUMN yang menyeretnya diam-diam.
        DB::statement('DROP INDEX IF EXISTS idx_artikel_simhash');
        DB::statement('DROP INDEX IF EXISTS idx_artikel_asli');
        DB::statement('ALTER TABLE artikel DROP CONSTRAINT IF EXISTS chk_artikel_induk_sesuai_dedup');
        DB::statement('ALTER TABLE artikel DROP CONSTRAINT IF EXISTS chk_artikel_status_dedup');

        Schema::table('artikel', function (Blueprint $table) {
            $table->dropColumn(['hash_isi', 'simhash', 'status_dedup', 'artikel_induk_id', 'skor_kemiripan']);
        });

        Schema::table('ringkasan_harian', function (Blueprint $table) {
            $table->dropColumn('jumlah_salinan');
        });

        Schema::dropIfExists('url_dibuang');
    }

    public function down(): void
    {
        Schema::table('artikel', function (Blueprint $table) {
            $table->char('hash_isi', 64)->nullable();
            $table->bigInteger('simhash')->nullable();
            $table->string('status_dedup', 20)->default('asli');
            $table->foreignId('artikel_induk_id')->nullable()->constrained('artikel')->nullOnDelete();
            $table->float('skor_kemiripan')->nullable();
        });

        DB::statement("ALTER TABLE artikel ADD CONSTRAINT chk_artikel_status_dedup
            CHECK (status_dedup IN ('asli','salinan'))");
        DB::statement('ALTER TABLE artikel ADD CONSTRAINT chk_artikel_induk_sesuai_dedup CHECK (
            (status_dedup = \'asli\' AND artikel_induk_id IS NULL) OR
            (status_dedup = \'salinan\' AND artikel_induk_id IS NOT NULL)
        )');
        DB::statement("CREATE INDEX idx_artikel_asli ON artikel (diambil_at DESC)
            WHERE status_dedup = 'asli'");
        DB::statement('CREATE INDEX idx_artikel_simhash ON artikel (simhash)');

        Schema::table('ringkasan_harian', function (Blueprint $table) {
            $table->integer('jumlah_salinan')->default(0);
        });

        Schema::create('url_dibuang', function (Blueprint $table) {
            $table->id();
            $table->string('url_kanonik', 1000)->unique();
            $table->string('alasan', 100)->nullable();
            $table->timestampTz('dibuang_at');
        });
    }
};
