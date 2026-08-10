<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bagian dashboard eksekutif yang ditulis Gemini, bukan dihitung Postgres.
 *
 * Satu baris per periode preset, berisi ringkasan naratif sekaligus daftar
 * topiknya. Dokumen rancangan memisahkannya menjadi `executive_summaries` dan
 * `executive_topics`, tetapi keduanya lahir dari satu panggilan Gemini yang
 * sama, dibaca bersamaan oleh satu halaman, dan kedaluwarsa bersamaan. Dua
 * tabel berarti dua kunci unik yang harus dijaga tetap sinkron tanpa ada yang
 * pernah membacanya terpisah.
 *
 * Barisnya tidak ditimpa tiap hari. Riwayat yang tertinggal adalah mekanisme
 * cadangan saat Gemini gagal: halaman menampilkan narasi terakhir yang berhasil
 * beserta waktu pembuatannya, sementara seluruh angka tetap dari data terbaru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('narasi_eksekutif', function (Blueprint $table) {
            $table->id();
            // today, 7d, 30d, 90d. Sama persis dengan pintasan di pemilih
            // rentang, supaya rentang yang dilihat pimpinan selalu punya narasi.
            $table->string('periode', 10);
            // Dalam WITA, seperti seluruh tabel agregasi lain.
            $table->date('dari');
            $table->date('sampai');

            $table->string('nada', 10)->nullable();
            $table->string('judul', 300)->nullable();
            $table->text('ringkasan')->nullable();
            // Satu kalimat yang menjelaskan arah grafik tren. Grafik garis
            // bertumpuk adalah bagian halaman yang paling sering salah dibaca
            // orang yang tidak terbiasa membaca grafik, dan kalimat di bawahnya
            // jauh lebih murah daripada mengganti jenis grafiknya.
            $table->text('penjelasan_tren')->nullable();
            $table->jsonb('poin')->nullable();
            $table->jsonb('perhatian')->nullable();
            $table->jsonb('nada_ringkas')->nullable();
            // Topik beserta statistiknya. Statistiknya dihitung Laravel setelah
            // Gemini mengembalikan pengelompokan, tidak pernah oleh Gemini.
            $table->jsonb('topik')->nullable();

            $table->integer('jumlah_artikel')->default(0);

            /**
             * Sidik data yang menjadi masukan narasi ini.
             *
             * Tanpa ini scheduler memanggil Gemini tiap jam untuk data yang
             * tidak berubah, dan pada malam hari saat tidak ada berita terbit
             * itu berarti puluhan panggilan yang menghasilkan kalimat yang sama.
             */
            $table->string('sidik', 64);

            $table->string('model', 80)->nullable();
            $table->timestampTz('dibuat_at')->useCurrent();
            $table->timestamps();

            $table->index(['periode', 'sampai']);
        });

        DB::statement("ALTER TABLE narasi_eksekutif ADD CONSTRAINT chk_narasi_periode
            CHECK (periode IN ('today','7d','30d','90d'))");
        DB::statement("ALTER TABLE narasi_eksekutif ADD CONSTRAINT chk_narasi_nada
            CHECK (nada IS NULL OR nada IN ('positif','netral','negatif','campuran'))");

        DB::statement('CREATE UNIQUE INDEX uq_narasi_eksekutif
            ON narasi_eksekutif (periode, dari, sampai)');
    }

    public function down(): void
    {
        Schema::dropIfExists('narasi_eksekutif');
    }
};
