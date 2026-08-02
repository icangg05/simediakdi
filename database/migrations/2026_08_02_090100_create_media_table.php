<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->string('slug', 150)->unique();
            $table->string('jenis', 20)->default('online');
            $table->string('tier', 20)->default('lokal');
            $table->string('url_website', 255)->nullable();
            $table->string('domain', 120)->nullable()->unique();
            $table->string('logo_path', 255)->nullable();
            $table->string('kota', 100)->nullable();
            $table->string('provinsi', 100)->nullable();
            $table->boolean('partner')->default(false);
            $table->string('nama_pic', 120)->nullable();
            $table->string('kontak_pic', 120)->nullable();
            $table->text('catatan')->nullable();
            $table->boolean('aktif')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['partner', 'aktif']);
        });

        DB::statement("ALTER TABLE media ADD CONSTRAINT chk_media_jenis
            CHECK (jenis IN ('online','cetak','tv','radio'))");
        DB::statement("ALTER TABLE media ADD CONSTRAINT chk_media_tier
            CHECK (tier IN ('nasional','regional','lokal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
