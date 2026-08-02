<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kata_kunci_periode', function (Blueprint $table) {
            $table->id();
            // Null = lintas semua konteks.
            $table->foreignId('konteks_pantauan_id')->nullable()->constrained('konteks_pantauan')->cascadeOnDelete();
            $table->string('granularitas', 10);
            $table->date('periode_mulai');
            $table->date('periode_akhir');
            $table->string('istilah', 120);
            $table->integer('frekuensi');
            $table->integer('jumlah_artikel');
            // Frekuensi periode ini dibagi rata-rata 4 periode sebelumnya.
            $table->float('skor_lonjakan')->nullable();
            $table->string('sentimen_dominan', 10)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['granularitas', 'periode_mulai', 'skor_lonjakan']);
        });

        DB::statement("ALTER TABLE kata_kunci_periode ADD CONSTRAINT chk_kata_kunci_granularitas
            CHECK (granularitas IN ('harian','mingguan'))");
        DB::statement("ALTER TABLE kata_kunci_periode ADD CONSTRAINT chk_kata_kunci_sentimen
            CHECK (sentimen_dominan IS NULL OR sentimen_dominan IN ('negatif','netral','positif'))");

        // NULLS NOT DISTINCT wajib: konteks_pantauan_id null menandai baris lintas
        // konteks, dan tanpa ini ON CONFLICT tidak pernah cocok sehingga barisnya
        // menumpuk setiap kali job dijalankan ulang.
        DB::statement('CREATE UNIQUE INDEX uq_kata_kunci_periode
            ON kata_kunci_periode (konteks_pantauan_id, granularitas, periode_mulai, istilah)
            NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('kata_kunci_periode');
    }
};
