<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('konteks_pantauan', function (Blueprint $table) {
            $table->id();
            // Dikirim apa adanya ke model IndoBERT sebagai input konteks.
            $table->string('nama', 200);
            $table->string('slug', 200)->unique();
            $table->text('deskripsi')->nullable();
            $table->jsonb('kata_kunci')->nullable();
            $table->boolean('utama')->default(false);
            $table->smallInteger('urutan')->default(0);
            $table->boolean('aktif')->default(true);
            $table->timestamps();

            $table->index(['aktif', 'urutan']);
        });

        DB::statement('CREATE UNIQUE INDEX uq_konteks_utama
            ON konteks_pantauan (utama) WHERE utama = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('konteks_pantauan');
    }
};
