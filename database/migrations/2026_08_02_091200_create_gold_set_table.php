<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gold_set', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artikel_id')->constrained('artikel')->cascadeOnDelete();
            $table->foreignId('konteks_pantauan_id')->constrained('konteks_pantauan')->cascadeOnDelete();
            $table->string('label_gold', 10);
            $table->boolean('relevan_gold');
            $table->foreignId('dilabeli_oleh')->constrained('users')->restrictOnDelete();
            $table->timestampTz('dilabeli_at');
            // Ronde 2 untuk mengukur konsistensi pelabel yang sama.
            $table->smallInteger('ronde')->default(1);
            $table->text('catatan')->nullable();

            $table->unique(['artikel_id', 'konteks_pantauan_id', 'ronde'], 'uq_gold_set_artikel_konteks_ronde');
        });

        DB::statement("ALTER TABLE gold_set ADD CONSTRAINT chk_gold_set_label
            CHECK (label_gold IN ('negatif','netral','positif'))");
        DB::statement('ALTER TABLE gold_set ADD CONSTRAINT chk_gold_set_ronde
            CHECK (ronde IN (1,2))');

        Schema::create('evaluasi_model', function (Blueprint $table) {
            $table->id();
            $table->string('model_versi', 60);
            $table->timestampTz('dievaluasi_at');
            $table->integer('jumlah_sampel');
            $table->float('akurasi');
            $table->float('f1_macro');
            $table->float('f1_negatif');
            $table->float('f1_netral');
            $table->float('f1_positif');
            // Matriks 3x3.
            $table->jsonb('confusion_matrix');
            // Ambang yang berlaku saat evaluasi dijalankan.
            $table->float('ambang_keyakinan');
            $table->text('catatan')->nullable();

            $table->index(['dievaluasi_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluasi_model');
        Schema::dropIfExists('gold_set');
    }
};
