<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jenis aturan keempat: satu berita negatif, dikirim saat itu juga.
 *
 * Tiga jenis yang sudah ada dinilai berkala oleh `alert:periksa` setiap 15
 * menit, dan ketiganya menjawab pertanyaan tentang kumpulan: berapa banyak,
 * seberapa sering, sudah berapa lama diam. Pertanyaan itu memang hanya bisa
 * dijawab dengan menunggu.
 *
 * `berita_negatif` menjawab pertanyaan yang berbeda: ada satu berita buruk,
 * dan humas perlu tahu sekarang. Menunggunya sampai penilaian berkala
 * berikutnya menambah jeda sampai 15 menit pada kabar yang justru paling
 * mahal terlambat. Karena itu ia tidak dinilai `alert:periksa` sama sekali,
 * melainkan dipicu tepat setelah Gemini selesai menilai artikelnya.
 *
 * `artikel_id` di riwayat bukan sekadar keterangan tambahan. Ia yang menahan
 * pengiriman ganda lewat unique index, dan penjagaan itu harus ada di
 * database: dua worker bisa memproses dua job untuk artikel yang sama pada
 * detik yang sama, dan pemeriksaan "sudah pernah dikirim belum" di dalam PHP
 * akan lolos di keduanya. Nilai null tetap boleh berulang, jadi alert
 * berkala yang memang tidak menunjuk satu artikel tidak ikut terhalang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riwayat_alert', function (Blueprint $table) {
            $table->foreignId('artikel_id')->nullable()->after('aturan_alert_id')
                ->constrained('artikel')->cascadeOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX uq_riwayat_alert_artikel ON riwayat_alert (aturan_alert_id, artikel_id)');

        DB::statement('ALTER TABLE aturan_alert DROP CONSTRAINT chk_aturan_alert_jenis');
        DB::statement("ALTER TABLE aturan_alert ADD CONSTRAINT chk_aturan_alert_jenis CHECK (jenis IN ('lonjakan_negatif', 'kata_kunci_muncul', 'sumber_mati', 'berita_negatif'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM aturan_alert WHERE jenis = 'berita_negatif'");
        DB::statement('ALTER TABLE aturan_alert DROP CONSTRAINT chk_aturan_alert_jenis');
        DB::statement("ALTER TABLE aturan_alert ADD CONSTRAINT chk_aturan_alert_jenis CHECK (jenis IN ('lonjakan_negatif', 'kata_kunci_muncul', 'sumber_mati'))");

        DB::statement('DROP INDEX IF EXISTS uq_riwayat_alert_artikel');

        Schema::table('riwayat_alert', function (Blueprint $table) {
            $table->dropConstrainedForeignId('artikel_id');
        });
    }
};
