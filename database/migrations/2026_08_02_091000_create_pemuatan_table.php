<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pemuatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kontrak_id')->constrained('kontrak')->cascadeOnDelete();
            // Denormalisasi agar global scope peran media sederhana.
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            // Null jika dilaporkan manual dan belum ter-crawl.
            $table->foreignId('artikel_id')->nullable()->constrained('artikel')->nullOnDelete();
            $table->string('url', 1000);
            $table->string('judul', 500)->nullable();
            $table->date('tanggal_muat');
            $table->string('sumber_catatan', 20);
            // Unggahan media, pelengkap arsip sistem.
            $table->string('bukti_path', 255)->nullable();
            $table->string('status_ekstraksi', 20)->default('menunggu');
            // F-52: bukti permanen, tidak diubah walau artikel sumber berubah.
            $table->text('arsip_teks')->nullable();
            $table->string('arsip_screenshot_path', 255)->nullable();
            $table->timestampTz('arsip_diambil_at')->nullable();
            $table->string('status_verifikasi', 20)->default('menunggu');
            $table->foreignId('dilaporkan_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('diverifikasi_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('diverifikasi_at')->nullable();
            $table->text('alasan_penolakan')->nullable();
            $table->timestamps();

            $table->index(['media_id', 'status_verifikasi']);
            $table->index(['kontrak_id', 'status_verifikasi']);
        });

        DB::statement("ALTER TABLE pemuatan ADD CONSTRAINT chk_pemuatan_sumber_catatan
            CHECK (sumber_catatan IN ('otomatis','laporan_media','input_admin'))");
        DB::statement("ALTER TABLE pemuatan ADD CONSTRAINT chk_pemuatan_status_ekstraksi
            CHECK (status_ekstraksi IN ('menunggu','berhasil','gagal'))");
        DB::statement("ALTER TABLE pemuatan ADD CONSTRAINT chk_pemuatan_status_verifikasi
            CHECK (status_verifikasi IN ('menunggu','terverifikasi','ditolak'))");
        // Alasan penolakan wajib diisi saat ditolak.
        DB::statement("ALTER TABLE pemuatan ADD CONSTRAINT chk_pemuatan_alasan_penolakan CHECK (
            status_verifikasi <> 'ditolak' OR alasan_penolakan IS NOT NULL
        )");

        // Satu URL tidak boleh diklaim dua kali pada kontrak yang sama.
        DB::statement('CREATE UNIQUE INDEX uq_pemuatan_kontrak_url ON pemuatan (kontrak_id, url)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pemuatan');
    }
};
