<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membuang kontrak. Portal media tinggal pelaporan berita.
 *
 * Kontrak membawa serta target pemuatan, progres pro rata, peringatan tenggat,
 * dan pencocokan artikel ke kontrak. Seluruhnya hanya menjawab satu pertanyaan,
 * "sudah berapa banyak media ini memuat", dan pertanyaan itu tidak lagi
 * ditanyakan. Yang tersisa dari portal adalah alur yang sebenarnya dipakai:
 * media melihat berita yang sudah ditangkap sistem, lalu melaporkan yang
 * terlewat.
 *
 * Tiga hal ikut dibuang karena alasannya melekat pada kontrak:
 *
 * 1. Pemuatan `otomatis`. Barisnya cermin dari tabel artikel, dibuat hanya
 *    supaya realisasi kontrak bisa dihitung. Berita otomatis sekarang dibaca
 *    langsung dari tabel artikel.
 * 2. Kolom arsip (`arsip_teks`, `arsip_screenshot_path`, `arsip_diambil_at`).
 *    Alasannya adalah audit pembayaran kontrak. Isi berita yang diverifikasi
 *    sekarang masuk ke tabel artikel lewat crawler biasa.
 * 3. Aturan alert `kontrak_tertinggal`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pemuatan')->where('sumber_catatan', 'otomatis')->delete();

        // Index unik yang baru memakai (media_id, url). URL yang sama pernah
        // sah tercatat dua kali bila media punya dua kontrak, dan baris kembar
        // itu akan menolak index barunya. Yang tertua dipertahankan.
        DB::statement('DELETE FROM pemuatan a USING pemuatan b
            WHERE a.id > b.id AND a.media_id = b.media_id AND a.url = b.url');

        Schema::table('pemuatan', function (Blueprint $table) {
            // PostgreSQL ikut membuang foreign key dan index yang memuat kolom
            // ini, termasuk uq_pemuatan_kontrak_url.
            $table->dropColumn([
                'kontrak_id',
                'sumber_catatan',
                'arsip_teks',
                'arsip_screenshot_path',
                'arsip_diambil_at',
            ]);
        });

        DB::statement('ALTER TABLE pemuatan DROP CONSTRAINT IF EXISTS chk_pemuatan_sumber_catatan');
        DB::statement('CREATE UNIQUE INDEX uq_pemuatan_media_url ON pemuatan (media_id, url)');

        Schema::dropIfExists('kontrak');

        DB::table('aturan_alert')->where('jenis', 'kontrak_tertinggal')->delete();
        DB::statement('ALTER TABLE aturan_alert DROP CONSTRAINT IF EXISTS chk_aturan_alert_jenis');
        DB::statement("ALTER TABLE aturan_alert ADD CONSTRAINT chk_aturan_alert_jenis
            CHECK (jenis IN ('lonjakan_negatif','kata_kunci_muncul','sumber_mati'))");
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Penghapusan kontrak tidak bisa dibatalkan lewat migration. '
            .'Skema dan data kontrak harus dibangun ulang dari riwayat git dan cadangan database.'
        );
    }
};
