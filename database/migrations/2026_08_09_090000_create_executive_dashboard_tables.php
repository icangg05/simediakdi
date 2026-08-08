<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('period_type', 10);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('overall_tone', 10)->nullable();
            $table->string('headline', 300)->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('key_points')->nullable();
            $table->jsonb('attention_required')->nullable();
            $table->jsonb('sentiment_summary')->nullable();
            $table->unsignedInteger('article_count')->default(0);
            $table->char('fingerprint', 64);
            $table->string('ai_provider', 30)->nullable();
            $table->string('ai_model', 100)->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampsTz();

            $table->unique(['period_type', 'start_date', 'end_date']);
            $table->index(['period_type', 'generated_at']);
        });

        Schema::create('executive_topics', function (Blueprint $table) {
            $table->id();
            $table->string('period_type', 10);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('title', 300);
            $table->text('summary')->nullable();
            $table->unsignedInteger('article_count')->default(0);
            $table->unsignedInteger('positive_count')->default(0);
            $table->unsignedInteger('neutral_count')->default(0);
            $table->unsignedInteger('negative_count')->default(0);
            $table->unsignedInteger('source_count')->default(0);
            $table->string('dominant_sentiment', 10)->nullable();
            $table->string('trend', 12)->nullable();
            $table->unsignedInteger('priority_score')->default(0);
            $table->string('priority_level', 10)->nullable();
            $table->jsonb('article_ids');
            $table->char('fingerprint', 64);
            $table->timestampTz('generated_at')->nullable();
            $table->timestampsTz();

            $table->index(['period_type', 'start_date', 'end_date']);
            $table->index(['priority_level', 'priority_score']);
        });

        Schema::create('executive_ai_logs', function (Blueprint $table) {
            $table->id();
            $table->string('task', 20);
            $table->string('period_type', 10);
            $table->string('provider', 30);
            $table->string('model', 100);
            $table->unsignedInteger('input_article_count')->default(0);
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 10);
            $table->text('error')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampsTz();

            $table->index(['task', 'period_type', 'created_at']);
        });

        DB::statement("ALTER TABLE executive_summaries ADD CONSTRAINT chk_executive_summary_tone CHECK (overall_tone IS NULL OR overall_tone IN ('positif','netral','negatif','campuran'))");
        DB::statement("ALTER TABLE executive_topics ADD CONSTRAINT chk_executive_topic_sentiment CHECK (dominant_sentiment IS NULL OR dominant_sentiment IN ('positif','netral','negatif'))");
        DB::statement("ALTER TABLE executive_topics ADD CONSTRAINT chk_executive_topic_trend CHECK (trend IS NULL OR trend IN ('baru','stabil','meningkat','menurun'))");
        DB::statement("ALTER TABLE executive_topics ADD CONSTRAINT chk_executive_topic_priority CHECK (priority_level IS NULL OR priority_level IN ('rendah','sedang','tinggi'))");
        DB::statement("ALTER TABLE executive_ai_logs ADD CONSTRAINT chk_executive_ai_log_status CHECK (status IN ('berjalan','berhasil','gagal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_ai_logs');
        Schema::dropIfExists('executive_topics');
        Schema::dropIfExists('executive_summaries');
    }
};
