<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analisis_sentimen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artikel_id')->constrained('artikel')->cascadeOnDelete();
            $table->foreignId('konteks_pantauan_id')->constrained('konteks_pantauan')->cascadeOnDelete();
            $table->boolean('relevan');
            $table->float('keyakinan_relevansi')->nullable();
            $table->string('label_model', 10)->nullable();
            $table->float('skor_negatif')->nullable();
            $table->float('skor_netral')->nullable();
            $table->float('skor_positif')->nullable();
            $table->float('keyakinan')->nullable();
            $table->boolean('perlu_review')->default(false);
            $table->string('model_versi', 60)->nullable();
            $table->timestampTz('dianalisis_at')->nullable();
            // Mengalahkan label_model.
            $table->string('label_manual', 10)->nullable();
            $table->foreignId('dikoreksi_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('dikoreksi_at')->nullable();
            $table->text('catatan_koreksi')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE analisis_sentimen ADD CONSTRAINT chk_analisis_label_model
            CHECK (label_model IS NULL OR label_model IN ('negatif','netral','positif'))");
        DB::statement("ALTER TABLE analisis_sentimen ADD CONSTRAINT chk_analisis_label_manual
            CHECK (label_manual IS NULL OR label_manual IN ('negatif','netral','positif'))");

        // Kolom generated agar tidak ada satu query pun yang lupa COALESCE.
        DB::statement('ALTER TABLE analisis_sentimen ADD COLUMN label_efektif varchar(10)
            GENERATED ALWAYS AS (COALESCE(label_manual, label_model)) STORED');

        DB::statement('CREATE UNIQUE INDEX uq_analisis_artikel_konteks
            ON analisis_sentimen (artikel_id, konteks_pantauan_id)');
        DB::statement('CREATE INDEX idx_analisis_konteks_label
            ON analisis_sentimen (konteks_pantauan_id, label_efektif)
            WHERE relevan = true');
        DB::statement('CREATE INDEX idx_analisis_perlu_review
            ON analisis_sentimen (perlu_review) WHERE perlu_review = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('analisis_sentimen');
    }
};
