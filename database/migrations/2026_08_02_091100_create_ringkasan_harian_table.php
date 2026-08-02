<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ringkasan_harian', function (Blueprint $table) {
            $table->id();
            // Dalam WITA.
            $table->date('tanggal');
            // Null = agregat semua media.
            $table->foreignId('media_id')->nullable()->constrained('media')->cascadeOnDelete();
            // Null = agregat semua konteks.
            $table->foreignId('konteks_pantauan_id')->nullable()->constrained('konteks_pantauan')->cascadeOnDelete();
            // Hanya yang status_dedup = asli.
            $table->integer('jumlah_artikel')->default(0);
            // Ditampilkan terpisah agar angka tetap dipercaya.
            $table->integer('jumlah_salinan')->default(0);
            $table->integer('jumlah_negatif')->default(0);
            $table->integer('jumlah_netral')->default(0);
            $table->integer('jumlah_positif')->default(0);
            $table->integer('jumlah_perlu_review')->default(0);
            $table->timestampTz('dihitung_at')->useCurrent();

            $table->index(['tanggal']);
        });

        // NULLS NOT DISTINCT wajib: baris dashboard eksekutif punya media_id dan
        // konteks_pantauan_id null. Dengan perilaku default (NULLS DISTINCT),
        // INSERT ... ON CONFLICT tidak pernah cocok dan baris duplikat menumpuk
        // setiap 10 menit sehingga angka dashboard salah.
        DB::statement('CREATE UNIQUE INDEX uq_ringkasan_harian
            ON ringkasan_harian (tanggal, media_id, konteks_pantauan_id)
            NULLS NOT DISTINCT');
    }

    public function down(): void
    {
        Schema::dropIfExists('ringkasan_harian');
    }
};
