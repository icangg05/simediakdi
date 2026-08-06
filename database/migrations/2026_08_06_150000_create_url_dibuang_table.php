<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nisan untuk URL artikel yang sudah dibuang.
 *
 * Deduplikasi lapis 1 bersandar pada index unique `artikel.url_kanonik`.
 * Begitu barisnya dihapus, URL-nya ikut hilang dari index itu, dan crawl
 * berikutnya membaca artikel yang sama sebagai berita baru. Itu bukan teori:
 * 206 dari 207 artikel yang masuk pada crawl pertama setelah pembersihan
 * adalah artikel yang baru saja dihapus, dan seluruhnya antre dinilai Gemini
 * untuk kedua kalinya demi mendapat jawaban yang sama persis.
 *
 * Tabel ini menyimpan jejaknya supaya penghapusan bertahan. Isinya sengaja
 * kurus: satu URL, satu alasan, satu waktu. Bukan arsip artikel, hanya penanda
 * bahwa URL ini pernah ditolak dan tidak perlu ditawarkan lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('url_dibuang', function (Blueprint $table) {
            $table->id();
            // Panjang dan tipe menyamai artikel.url_kanonik, karena nilainya
            // memang disalin dari sana dan dibandingkan langsung dengannya.
            $table->string('url_kanonik', 1000)->unique();
            $table->string('alasan', 100)->nullable();
            $table->timestampTz('dibuang_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('url_dibuang');
    }
};
