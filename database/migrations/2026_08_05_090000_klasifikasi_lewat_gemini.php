<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gemini menjadi penilai utama, IndoBERT menjadi cadangan. Dokumen 13.
 *
 * Tidak ada tabel baru. Dokumen 13 bagian 17 merancang `ai_predictions`,
 * `manual_labels`, dan `training_samples`, tetapi ketiganya sudah ada di sini
 * dengan nama Indonesia: `prediksi_relevansi` yang append-only,
 * `analisis_sentimen.label_manual` beserta kolom generated `label_efektif`, dan
 * `sampel_relevansi` beserta snapshot serta split validator-nya.
 *
 * Membuat tabel kedua berarti dua antrean review, dua ekspor dataset, dan dua
 * gerbang mutu yang harus disamakan selamanya. Yang diperlukan hanya kolom
 * penjelas: siapa yang memutuskan, dengan model apa, atas dasar kutipan mana.
 *
 * Nama kolom baru mengikuti dokumen 13 apa adanya, dalam bahasa Inggris.
 * Konsisten lintas dua tabel lebih berguna daripada konsisten di dalam satu
 * tabel, karena kode yang membacanya adalah kode yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->prediksiRelevansi();
        $this->analisisSentimen();
    }

    /**
     * Prediksi relevansi sekarang bisa datang dari dua penyedia yang berbeda
     * sifat: yang satu mengembalikan probabilitas, yang satu mengembalikan
     * alasan dan kutipan.
     */
    private function prediksiRelevansi(): void
    {
        Schema::table('prediksi_relevansi', function (Blueprint $table) {
            // Baris lama tidak mencatat konteks mana yang dinilai, padahal job
            // menulis satu baris per konteks aktif. Selama konteks utama hanya
            // satu, kehilangan itu tidak terasa. Begitu Gemini menyertakan
            // alasan dan kutipan per konteks, baris yang tidak bisa dipulangkan
            // ke konteksnya menjadi tidak ada gunanya.
            $table->foreignId('konteks_pantauan_id')->nullable()
                ->after('artikel_id')
                ->constrained('konteks_pantauan')->cascadeOnDelete();

            $table->string('provider', 20)->default('indobert')->after('versi_konteks_relevansi_id');
            $table->string('model', 100)->nullable()->after('provider');
            $table->string('reason_code', 60)->nullable()->after('confidence');
            $table->text('reason_summary')->nullable()->after('reason_code');
            $table->jsonb('evidence')->nullable()->after('reason_summary');
            $table->boolean('fallback_used')->default(false)->after('evidence');
            $table->string('fallback_reason', 60)->nullable()->after('fallback_used');
            $table->string('prompt_version', 40)->nullable()->after('fallback_reason');
        });

        // Gemini tidak punya versi model lokal, tidak punya ambang, dan tidak
        // menunjuk versi konteks. Memaksanya mengisi kolom itu berarti membuat
        // baris palsu di tabel laboratorium hanya agar foreign key senang.
        DB::statement('ALTER TABLE prediksi_relevansi ALTER COLUMN versi_model_relevansi_id DROP NOT NULL');
        DB::statement('ALTER TABLE prediksi_relevansi ALTER COLUMN versi_konteks_relevansi_id DROP NOT NULL');

        // Gemini tidak mengembalikan probabilitas. Mengisinya dengan 1,0 dan 0,0
        // akan membuat kolom ini terlihat terukur, lalu dipakai menyetel ambang,
        // dan ambangnya salah tanpa ada yang tahu sebabnya. Kosong berarti
        // kosong, bukan nol.
        DB::statement('ALTER TABLE prediksi_relevansi ALTER COLUMN probabilitas_relevan DROP NOT NULL');
        DB::statement('ALTER TABLE prediksi_relevansi ALTER COLUMN probabilitas_tidak_relevan DROP NOT NULL');
        DB::statement('ALTER TABLE prediksi_relevansi ALTER COLUMN confidence DROP NOT NULL');

        DB::statement('ALTER TABLE prediksi_relevansi DROP CONSTRAINT chk_prediksi_label');
        DB::statement("ALTER TABLE prediksi_relevansi ADD CONSTRAINT chk_prediksi_label
            CHECK (label_prediksi IN ('relevan','tidak_relevan','perlu_review'))");

        DB::statement("ALTER TABLE prediksi_relevansi ADD CONSTRAINT chk_prediksi_provider
            CHECK (provider IN ('gemini','indobert'))");

        // IndoBERT yang benar-benar memutuskan wajib membawa probabilitasnya.
        // Tanpa pemeriksaan ini, satu bug pemetaan menghasilkan baris IndoBERT
        // tanpa angka yang tetap masuk diam-diam, dan baru ketahuan saat
        // evaluasi model kekurangan data tanpa alasan yang jelas.
        //
        // `perlu_review` dikecualikan karena di situlah IndoBERT justru belum
        // menghitung apa pun: model yang belum lulus gerbang mutu tidak
        // dijalankan sama sekali, dan layanan yang tidak mengembalikan baris
        // tidak memberi angka untuk disimpan.
        DB::statement("ALTER TABLE prediksi_relevansi ADD CONSTRAINT chk_prediksi_indobert_berangka
            CHECK (provider <> 'indobert'
                OR label_prediksi = 'perlu_review'
                OR probabilitas_relevan IS NOT NULL)");

        DB::statement('CREATE INDEX idx_prediksi_provider ON prediksi_relevansi (provider, predicted_at DESC)');
    }

    /**
     * Sentimen menyimpan alasan dan bukti di baris analisisnya sendiri.
     *
     * Kolom label tidak disentuh sama sekali. `label_manual` tetap mengalahkan
     * `label_model` lewat kolom generated `label_efektif`, dan `perlu_review`
     * tetap menjadi satu-satunya penanda antrean manusia. Label `perlu_review`
     * dari Gemini dipetakan menjadi `label_model` kosong ditambah
     * `perlu_review` true, bukan dipaksa masuk salah satu dari tiga nilai yang
     * diizinkan constraint.
     */
    private function analisisSentimen(): void
    {
        Schema::table('analisis_sentimen', function (Blueprint $table) {
            $table->string('provider', 20)->nullable()->after('model_versi');
            $table->string('reason_code', 60)->nullable()->after('provider');
            $table->text('reason_summary')->nullable()->after('reason_code');
            $table->jsonb('evidence')->nullable()->after('reason_summary');
            $table->boolean('fallback_used')->default(false)->after('evidence');
            $table->string('fallback_reason', 60)->nullable()->after('fallback_used');
            $table->string('prompt_version', 40)->nullable()->after('fallback_reason');

            // Penanda bahwa `relevan` di baris ini diputuskan manusia.
            //
            // Sampai sekarang antrean review menulis keputusannya langsung ke
            // `relevan` tanpa meninggalkan jejak, dan komentar di controller
            // menyatakan keputusan itu tidak pernah ditimpa analisis ulang.
            // Tidak ada yang menegakkannya: menjalankan ulang AnalisisRelevansi
            // menimpa kolom yang sama, diam-diam, dan artikel yang sudah
            // diperiksa manusia kembali menjadi tebakan model.
            //
            // Kolomnya sengaja terpisah dari `relevan`, bukan menggantikannya.
            // `relevan` dibaca indeks parsial, join antrean review, dan seluruh
            // agregasi dashboard. Menambah kolom efektif berarti menyunting
            // semuanya untuk satu penanda.
            $table->boolean('relevan_manual')->nullable()->after('relevan');
            $table->foreignId('relevan_dikoreksi_oleh')->nullable()
                ->after('relevan_manual')
                ->constrained('users')->nullOnDelete();
            $table->timestampTz('relevan_dikoreksi_at')->nullable()->after('relevan_dikoreksi_oleh');
        });

        DB::statement("ALTER TABLE analisis_sentimen ADD CONSTRAINT chk_analisis_provider
            CHECK (provider IS NULL OR provider IN ('gemini','indobert'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE analisis_sentimen DROP CONSTRAINT chk_analisis_provider');

        Schema::table('analisis_sentimen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('relevan_dikoreksi_oleh');
            $table->dropColumn([
                'provider', 'reason_code', 'reason_summary', 'evidence',
                'fallback_used', 'fallback_reason', 'prompt_version',
                'relevan_manual', 'relevan_dikoreksi_at',
            ]);
        });

        DB::statement('DROP INDEX IF EXISTS idx_prediksi_provider');
        DB::statement('ALTER TABLE prediksi_relevansi DROP CONSTRAINT chk_prediksi_indobert_berangka');
        DB::statement('ALTER TABLE prediksi_relevansi DROP CONSTRAINT chk_prediksi_provider');

        // Baris Gemini dibuang lebih dulu. Ia tidak punya probabilitas dan tidak
        // punya versi model, jadi tidak mungkin memenuhi kolom yang sebentar
        // lagi kembali NOT NULL. Membuangnya adalah kehilangan yang disengaja
        // dan bisa diulang, sedangkan migration yang gagal di tengah tidak.
        DB::statement("DELETE FROM prediksi_relevansi WHERE provider = 'gemini'");

        DB::statement('ALTER TABLE prediksi_relevansi DROP CONSTRAINT chk_prediksi_label');
        DB::statement("DELETE FROM prediksi_relevansi WHERE label_prediksi = 'perlu_review'");
        DB::statement("ALTER TABLE prediksi_relevansi ADD CONSTRAINT chk_prediksi_label
            CHECK (label_prediksi IN ('relevan','tidak_relevan'))");

        DB::statement('ALTER TABLE prediksi_relevansi ALTER COLUMN confidence SET NOT NULL');
        DB::statement('ALTER TABLE prediksi_relevansi ALTER COLUMN probabilitas_tidak_relevan SET NOT NULL');
        DB::statement('ALTER TABLE prediksi_relevansi ALTER COLUMN probabilitas_relevan SET NOT NULL');
        DB::statement('ALTER TABLE prediksi_relevansi ALTER COLUMN versi_konteks_relevansi_id SET NOT NULL');
        DB::statement('ALTER TABLE prediksi_relevansi ALTER COLUMN versi_model_relevansi_id SET NOT NULL');

        Schema::table('prediksi_relevansi', function (Blueprint $table) {
            $table->dropColumn([
                'provider', 'model', 'reason_code', 'reason_summary', 'evidence',
                'fallback_used', 'fallback_reason', 'prompt_version',
            ]);
            $table->dropConstrainedForeignId('konteks_pantauan_id');
        });
    }
};
