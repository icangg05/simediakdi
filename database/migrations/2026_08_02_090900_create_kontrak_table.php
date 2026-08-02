<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kontrak', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->restrictOnDelete();
            $table->string('nomor', 120)->nullable();
            $table->string('judul', 250);
            $table->string('jenis', 30);
            $table->date('tanggal_mulai');
            $table->date('tanggal_akhir');
            $table->decimal('nilai', 15, 2)->nullable();
            $table->integer('target_pemuatan')->nullable();
            $table->string('berkas_path', 255)->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('catatan')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['media_id', 'status']);
            // F-26: kontrak yang mendekati tenggat.
            $table->index(['status', 'tanggal_akhir']);
        });

        DB::statement("ALTER TABLE kontrak ADD CONSTRAINT chk_kontrak_jenis
            CHECK (jenis IN ('advertorial','publikasi','banner','lain'))");
        DB::statement("ALTER TABLE kontrak ADD CONSTRAINT chk_kontrak_status
            CHECK (status IN ('draft','aktif','selesai','batal'))");
        DB::statement('ALTER TABLE kontrak ADD CONSTRAINT chk_kontrak_rentang_tanggal
            CHECK (tanggal_akhir >= tanggal_mulai)');
    }

    public function down(): void
    {
        Schema::dropIfExists('kontrak');
    }
};
