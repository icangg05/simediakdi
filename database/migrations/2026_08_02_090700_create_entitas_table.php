<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitas', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 200);
            // Huruf kecil, tanpa aksen, untuk pencocokan.
            $table->string('nama_normal', 200)->unique();
            $table->string('jenis', 20);
            $table->jsonb('alias')->nullable();
            $table->foreignId('digabung_ke')->nullable()->constrained('entitas')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE entitas ADD CONSTRAINT chk_entitas_jenis
            CHECK (jenis IN ('orang','organisasi','opd','lokasi','program','lain'))");

        Schema::create('artikel_entitas', function (Blueprint $table) {
            $table->foreignId('artikel_id')->constrained('artikel')->cascadeOnDelete();
            $table->foreignId('entitas_id')->constrained('entitas')->cascadeOnDelete();
            $table->smallInteger('jumlah_sebutan')->default(1);

            $table->primary(['artikel_id', 'entitas_id']);
            // Arah query sebaliknya: entitas ini muncul di artikel mana saja.
            $table->index(['entitas_id', 'artikel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artikel_entitas');
        Schema::dropIfExists('entitas');
    }
};
