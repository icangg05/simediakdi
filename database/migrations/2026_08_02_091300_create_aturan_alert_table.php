<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aturan_alert', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->string('jenis', 30);
            $table->foreignId('konteks_pantauan_id')->nullable()->constrained('konteks_pantauan')->cascadeOnDelete();
            // Parameter spesifik per jenis.
            $table->jsonb('kondisi');
            $table->float('ambang')->nullable();
            $table->smallInteger('jendela_jam')->default(6);
            // F-40: pembatas pengiriman berulang.
            $table->smallInteger('jeda_minimal_jam')->default(6);
            $table->string('kanal', 20)->default('telegram');
            // Chat ID atau alamat email.
            $table->jsonb('penerima');
            $table->boolean('aktif')->default(true);
            $table->timestampTz('dipicu_terakhir_at')->nullable();
            $table->timestamps();

            $table->index(['aktif', 'jenis']);
        });

        DB::statement("ALTER TABLE aturan_alert ADD CONSTRAINT chk_aturan_alert_jenis
            CHECK (jenis IN ('lonjakan_negatif','kata_kunci_muncul','sumber_mati','kontrak_tertinggal'))");
        DB::statement("ALTER TABLE aturan_alert ADD CONSTRAINT chk_aturan_alert_kanal
            CHECK (kanal IN ('telegram','email'))");

        Schema::create('riwayat_alert', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aturan_alert_id')->constrained('aturan_alert')->cascadeOnDelete();
            $table->timestampTz('dipicu_at');
            // Isi pesan yang dikirim.
            $table->string('ringkasan', 500);
            // Artikel dan angka yang memicunya, untuk audit.
            $table->jsonb('payload')->nullable();
            $table->string('status_kirim', 20);
            $table->text('pesan_error')->nullable();
            $table->timestampTz('dibaca_at')->nullable();

            $table->index(['aturan_alert_id', 'dipicu_at']);
        });

        DB::statement("ALTER TABLE riwayat_alert ADD CONSTRAINT chk_riwayat_alert_status_kirim
            CHECK (status_kirim IN ('terkirim','gagal','tertunda'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_alert');
        Schema::dropIfExists('aturan_alert');
    }
};
