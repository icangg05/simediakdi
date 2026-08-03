<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relevansi berpindah dari classifier IndoBERT ke kemiripan makna.
 *
 * Skornya kini cosine similarity antara vektor artikel dan vektor deskripsi
 * konteks, keduanya dari model embedding yang sama. Yang paling berharga bukan
 * kecepatannya: karena skor dihitung dari vektor tersimpan, mengubah ambang
 * cukup satu kueri atas seluruh korpus, tanpa satu pun inferensi model. Ambang
 * yang harus dicoba puluhan kali hanya akan benar-benar disetel kalau
 * mencobanya murah.
 *
 * Dokumen 05 bagian 2, dokumen 03 changelog 1.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artikel', function (Blueprint $table) {
            // Vektor kedua, dari teks terfokus: judul, kategori, tag,
            // ringkasan, dan potongan kalimat di sekitar sebutan Pemkot.
            //
            // Sengaja terpisah dari `embedding`. Deduplikasi butuh gambaran
            // artikel seutuhnya, relevansi butuh gambaran yang terfokus. Satu
            // vektor tidak bisa melayani keduanya: dengan vektor isi penuh,
            // dua artikel berbeda yang sama-sama membahas Pemkot akan terlihat
            // seperti salinan.
            $table->vector('embedding_relevansi', 384)->nullable();
        });

        // Tanpa index HNSW, dan itu disengaja. Index tetangga terdekat
        // melayani pencarian "vektor mana yang paling mirip dengan ini",
        // sedangkan kolom ini selalu dibandingkan terhadap satu vektor yang
        // sama, yaitu vektor konteks utama.
        Schema::table('konteks_pantauan', function (Blueprint $table) {
            $table->text('deskripsi_model')->nullable();
            $table->vector('embedding', 384)->nullable();
        });

        Schema::table('analisis_sentimen', function (Blueprint $table) {
            // Isinya bukan lagi probabilitas keluaran classifier melainkan
            // cosine similarity. Namanya ikut berubah supaya tidak ada yang
            // membacanya sebagai persentase keyakinan.
            $table->renameColumn('keyakinan_relevansi', 'skor_relevansi');
        });

        // Dua jalur baru yang sebelumnya tidak punya status: artikel yang
        // ditolak penyaring, dan artikel yang skornya di antara dua ambang.
        DB::statement('ALTER TABLE artikel DROP CONSTRAINT chk_artikel_status_proses');
        DB::statement("ALTER TABLE artikel ADD CONSTRAINT chk_artikel_status_proses
            CHECK (status_proses IN ('mentah','isi_diambil','dianalisis','perlu_review','tidak_relevan','selesai','gagal'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE artikel DROP CONSTRAINT chk_artikel_status_proses');
        DB::statement("UPDATE artikel SET status_proses = 'dianalisis'
            WHERE status_proses IN ('perlu_review','tidak_relevan')");
        DB::statement("ALTER TABLE artikel ADD CONSTRAINT chk_artikel_status_proses
            CHECK (status_proses IN ('mentah','isi_diambil','dianalisis','selesai','gagal'))");

        Schema::table('analisis_sentimen', function (Blueprint $table) {
            $table->renameColumn('skor_relevansi', 'keyakinan_relevansi');
        });

        Schema::table('konteks_pantauan', function (Blueprint $table) {
            $table->dropColumn(['deskripsi_model', 'embedding']);
        });

        Schema::table('artikel', function (Blueprint $table) {
            $table->dropColumn('embedding_relevansi');
        });
    }
};
