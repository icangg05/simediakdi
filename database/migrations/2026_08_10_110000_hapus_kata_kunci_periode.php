<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membuang tabel kata kunci beserta seluruh rantainya.
 *
 * Halaman Isu Hangat dihapus dari panel walikota, dan halaman itu satu-satunya
 * pembaca `kata_kunci_periode`. Yang tertinggal bukan sekadar tabel menganggur:
 * penjadwal menghitung n-gram dari seluruh artikel periode itu setiap jam,
 * pekerjaan terberat di seluruh jadwal, untuk baris yang tidak pernah dibuka
 * siapa pun.
 *
 * Peringatan `kata_kunci_muncul` di modul alert tidak terpengaruh. Aturan itu
 * mencari istilahnya langsung di judul dan isi artikel, tidak pernah lewat
 * tabel ini.
 *
 * `down()` mengembalikan bentuk tabelnya, bukan isinya. Seluruh barisnya
 * agregat turunan, dan penghitungnya ikut dihapus, jadi tidak ada yang bisa
 * mengisinya lagi tanpa mengembalikan kodenya lebih dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('kata_kunci_periode');
    }

    public function down(): void
    {
        Schema::create('kata_kunci_periode', function (Blueprint $table) {
            $table->id();
            $table->string('granularitas', 10);
            $table->date('periode_mulai');
            $table->date('periode_akhir');
            $table->string('istilah', 120);
            $table->integer('frekuensi');
            $table->integer('jumlah_artikel');
            $table->float('skor_lonjakan')->nullable();
            $table->string('sentimen_dominan', 10)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['granularitas', 'periode_mulai', 'skor_lonjakan']);
        });

        DB::statement("ALTER TABLE kata_kunci_periode ADD CONSTRAINT chk_kata_kunci_granularitas
            CHECK (granularitas IN ('harian','mingguan'))");
        DB::statement("ALTER TABLE kata_kunci_periode ADD CONSTRAINT chk_kata_kunci_sentimen
            CHECK (sentimen_dominan IS NULL OR sentimen_dominan IN ('negatif','netral','positif'))");

        DB::statement('CREATE UNIQUE INDEX uq_kata_kunci_periode
            ON kata_kunci_periode (granularitas, periode_mulai, istilah)');
    }
};
