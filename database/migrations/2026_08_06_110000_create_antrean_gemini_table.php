<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Antrean klasifikasi Gemini, sebagai tabel dan bukan hanya sebagai daftar
 * pekerjaan di Redis.
 *
 * Redis tahu apa yang belum dikerjakan, tetapi melupakan segalanya begitu
 * pekerjaan selesai. Halaman pemantauan perlu menjawab pertanyaan yang lain:
 * berapa yang sudah beres hari ini, mana yang gagal dan kenapa, dan berapa lama
 * lagi sisanya habis. Semua itu butuh riwayat, jadi antrean yang sebenarnya
 * ada di sini dan Redis hanya menjalankannya.
 *
 * Satu baris per artikel, bukan per percobaan. Artikel yang gagal lalu dicoba
 * lagi tetap satu baris dengan `percobaan` yang bertambah, supaya jumlah baris
 * menunggu selalu sama dengan jumlah artikel yang benar-benar tersisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('antrean_gemini', function (Blueprint $tabel) {
            $tabel->id();

            // Unik, dan itu penjaga utamanya. Pengisi antrean berjalan berulang
            // kali dan selalu menemukan kandidat yang sama selama pekerjaannya
            // belum jalan. Tanpa kunci unik, satu artikel bisa mengantre puluhan
            // kali dan membakar kuota untuk jawaban yang itu-itu juga.
            $tabel->foreignId('artikel_id')->unique()->constrained('artikel')->cascadeOnDelete();

            /*
             * 1 belum punya analisis sama sekali, 2 relevan yang labelnya dari
             * pipeline lama, 3 tidak relevan yang keputusannya dari pipeline
             * lama. Disimpan sebagai angka, bukan nama, karena satu-satunya
             * gunanya adalah diurutkan.
             */
            $tabel->unsignedTinyInteger('prioritas');

            $tabel->string('status', 12)->default('menunggu');
            $tabel->unsignedSmallInteger('percobaan')->default(0);
            $tabel->text('galat')->nullable();

            $tabel->timestampTz('dijadwalkan_at')->nullable();
            $tabel->timestampTz('dimulai_at')->nullable();
            $tabel->timestampTz('selesai_at')->nullable();

            $tabel->timestamps();

            // Urutan pengambilan pekerjaan berikutnya, persis seperti yang
            // dibaca pengisi antrean: status dulu, lalu prioritas, lalu urutan
            // masuk. Tanpa indeks ini setiap tarikan memindai seluruh tabel.
            $tabel->index(['status', 'prioritas', 'id']);
            $tabel->index('selesai_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antrean_gemini');
    }
};
