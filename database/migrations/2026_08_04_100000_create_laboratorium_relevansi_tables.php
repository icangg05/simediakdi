<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sebelas tabel Laboratorium Model Relevansi. Dokumen 10 bagian 16.
 *
 * Dibuat dalam satu migration karena foreign key-nya saling mengunci dan
 * urutan pembuatannya menentukan. Memecahnya menjadi sebelas berkas berarti
 * sebelas kesempatan menjalankan setengahnya lalu berhenti di tengah dengan
 * skema yang tidak bisa dipakai maupun dibatalkan.
 *
 * Yang membedakan tabel di sini dari `gold_set` yang digantikannya: setiap
 * keputusan menyimpan siapa, kapan, dan mengapa. Label tanpa alasan tidak bisa
 * ditinjau ulang enam bulan lagi, dan label yang tidak bisa ditinjau ulang
 * adalah label yang kesalahannya permanen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->versiKonteks();
        $this->versiThreshold();
        $this->sampel();
        $this->snapshot();
        $this->pelatihan();
        $this->versiModel();
        $this->prediksi();
        $this->evaluasi();
        $this->gerbangMutu();
        $this->ujiManual();
    }

    /**
     * Definisi konteks berversi.
     *
     * Model yang dilatih di bawah satu definisi tidak otomatis berlaku di
     * bawah definisi lain. Tanpa versi, perubahan satu kalimat aturan membuat
     * seluruh label lama menjawab pertanyaan yang berbeda tanpa ada yang tahu.
     */
    private function versiKonteks(): void
    {
        Schema::create('versi_konteks_relevansi', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->string('versi', 40);
            $table->string('slug', 150)->unique();
            $table->text('deskripsi_manusia');
            // Kalimat yang benar-benar dipasangkan ke artikel pada tokenizer.
            // Pendek dan persis, bukan paragraf aturan.
            $table->text('deskripsi_model');
            $table->jsonb('aturan_inklusi');
            $table->jsonb('aturan_eksklusi');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE versi_konteks_relevansi ADD CONSTRAINT chk_versi_konteks_status
            CHECK (status IN ('draft','active','archived'))");
    }

    /**
     * Ambang berversi, menggantikan dua nilai di `.env`.
     *
     * Ambang di environment tidak punya alasan, pemilik, dan tanggal, sehingga
     * tidak ada cara menjawab mengapa angkanya sekian dan siapa yang
     * menurunkannya. Ia juga berpasangan dengan model: mempromosikan model baru
     * tanpa mengganti ambangnya adalah cara tercepat merusak produksi.
     */
    private function versiThreshold(): void
    {
        Schema::create('versi_threshold_relevansi', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100);
            $table->float('relevant_threshold');
            $table->float('review_lower_bound');
            $table->float('review_upper_bound');
            $table->jsonb('source_overrides')->nullable();
            $table->text('reason');
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE versi_threshold_relevansi ADD CONSTRAINT chk_versi_threshold_status
            CHECK (status IN ('draft','active','archived'))");
        DB::statement('ALTER TABLE versi_threshold_relevansi ADD CONSTRAINT chk_versi_threshold_pita
            CHECK (review_lower_bound <= review_upper_bound)');
    }

    /**
     * Kandidat dataset beserta keputusan manusia atasnya.
     *
     * `artikel_id` nullable karena tidak semua sampel berasal dari crawler:
     * teks manual dan hasil uji URL juga bisa disimpan sebagai sampel.
     */
    private function sampel(): void
    {
        Schema::create('sampel_relevansi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artikel_id')->nullable()->constrained('artikel')->cascadeOnDelete();
            $table->string('sumber_dataset', 30);
            $table->text('judul');
            $table->text('excerpt')->nullable();
            $table->text('isi');
            $table->text('url')->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->timestampTz('tanggal_publikasi')->nullable();
            $table->jsonb('kategori_sumber')->nullable();
            $table->jsonb('tag_sumber')->nullable();
            $table->jsonb('metadata_sumber')->nullable();

            $table->string('label_manual', 20)->nullable();
            $table->string('alasan_label', 40)->nullable();
            $table->string('tingkat_kesulitan', 20)->default('normal');
            $table->string('status_label', 20)->default('belum_dilabeli');

            // Skor dan komponennya disimpan bersama supaya antrean bisa
            // menjelaskan dirinya sendiri. Antrean prioritas yang tidak bisa
            // ditanya alasannya akan diabaikan pelabel pada hari ketiga.
            $table->integer('priority_score')->default(0);
            $table->jsonb('priority_reasons')->nullable();

            // Seluruh anggota satu grup wajib jatuh di split yang sama.
            $table->unsignedBigInteger('duplicate_group_id')->nullable();

            $table->boolean('is_excluded')->default(false);
            $table->text('excluded_reason')->nullable();
            $table->foreignId('labeled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('labeled_at')->nullable();
            $table->timestampTz('last_reviewed_at')->nullable();
            $table->timestampsTz();

            $table->index('status_label');
            $table->index('label_manual');
            $table->index(['media_id', 'tanggal_publikasi']);
            $table->index('duplicate_group_id');
            $table->index('tingkat_kesulitan');
        });

        // Satu artikel satu sampel. Tanpa ini, backfill yang dijalankan dua
        // kali menggandakan dataset diam-diam, dan angka "artikel unik
        // berlabel" berhenti berarti apa pun.
        DB::statement('CREATE UNIQUE INDEX uq_sampel_artikel ON sampel_relevansi (artikel_id)
            WHERE artikel_id IS NOT NULL');
        DB::statement('CREATE INDEX idx_sampel_prioritas ON sampel_relevansi (priority_score DESC)
            WHERE status_label = \'belum_dilabeli\'');

        DB::statement("ALTER TABLE sampel_relevansi ADD CONSTRAINT chk_sampel_label
            CHECK (label_manual IS NULL OR label_manual IN ('relevan','tidak_relevan'))");
        DB::statement("ALTER TABLE sampel_relevansi ADD CONSTRAINT chk_sampel_status
            CHECK (status_label IN ('belum_dilabeli','sudah_dilabeli','perlu_review','dikeluarkan','terkunci_test'))");
        DB::statement("ALTER TABLE sampel_relevansi ADD CONSTRAINT chk_sampel_kesulitan
            CHECK (tingkat_kesulitan IN ('normal','hard_positive','hard_negative'))");
        DB::statement("ALTER TABLE sampel_relevansi ADD CONSTRAINT chk_sampel_sumber
            CHECK (sumber_dataset IN ('crawler','url_test','manual_text','production_error','import','migrated_gold_set'))");
        // Sampel yang dinyatakan sudah dilabeli tanpa label adalah baris yang
        // akan diam-diam masuk snapshot lalu dilatihkan sebagai kelas kosong.
        DB::statement("ALTER TABLE sampel_relevansi ADD CONSTRAINT chk_sampel_label_wajib
            CHECK (status_label <> 'sudah_dilabeli' OR label_manual IS NOT NULL)");
    }

    /**
     * Susunan dataset yang dibekukan untuk satu eksperimen.
     *
     * Label yang berubah setelah snapshot dibuat tidak boleh diam-diam
     * mengubah eksperimen lama. Itu sebabnya `label_at_snapshot` disalin, bukan
     * dibaca lewat join saat evaluasi dijalankan ulang.
     */
    private function snapshot(): void
    {
        Schema::create('snapshot_dataset_relevansi', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->string('versi', 40);
            $table->text('deskripsi')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('strategi_sampling', 40);
            $table->integer('random_seed');
            $table->string('versi_panduan_label', 20);
            $table->string('manifest_hash', 64)->nullable();
            $table->integer('total_relevan')->default(0);
            $table->integer('total_tidak_relevan')->default(0);
            $table->integer('total_train')->default(0);
            $table->integer('total_validation')->default(0);
            $table->integer('total_test')->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['nama', 'versi']);
        });

        DB::statement("ALTER TABLE snapshot_dataset_relevansi ADD CONSTRAINT chk_snapshot_status
            CHECK (status IN ('draft','validating','locked','invalidated','archived'))");
        DB::statement("ALTER TABLE snapshot_dataset_relevansi ADD CONSTRAINT chk_snapshot_strategi
            CHECK (strategi_sampling IN ('balanced','natural_distribution','balanced_with_hard_cases','custom'))");
        // Snapshot terkunci tanpa manifest hash tidak bisa dibuktikan isinya,
        // dan snapshot yang tidak bisa dibuktikan isinya bukan snapshot.
        DB::statement("ALTER TABLE snapshot_dataset_relevansi ADD CONSTRAINT chk_snapshot_terkunci_berhash
            CHECK (status <> 'locked' OR (manifest_hash IS NOT NULL AND locked_at IS NOT NULL))");

        Schema::create('item_snapshot_dataset_relevansi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_dataset_relevansi_id')
                ->constrained('snapshot_dataset_relevansi')->cascadeOnDelete();
            $table->foreignId('sampel_relevansi_id')
                ->constrained('sampel_relevansi')->cascadeOnDelete();
            $table->string('split', 15);
            $table->unsignedBigInteger('duplicate_group_id')->nullable();
            $table->string('label_at_snapshot', 20);
            $table->string('content_hash', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['snapshot_dataset_relevansi_id', 'sampel_relevansi_id'], 'uq_item_snapshot_sampel');
            $table->index(['snapshot_dataset_relevansi_id', 'split'], 'idx_item_snapshot_split');
        });

        DB::statement("ALTER TABLE item_snapshot_dataset_relevansi ADD CONSTRAINT chk_item_snapshot_split
            CHECK (split IN ('train','validation','test'))");
        DB::statement("ALTER TABLE item_snapshot_dataset_relevansi ADD CONSTRAINT chk_item_snapshot_label
            CHECK (label_at_snapshot IN ('relevan','tidak_relevan'))");
    }

    /**
     * Satu baris per training run.
     *
     * `parent_run_id` menautkan percobaan ulang ke percobaan yang gagal, bukan
     * menggantikannya. Riwayat kegagalan adalah satu-satunya cara mengetahui
     * konfigurasi mana yang sudah pernah dicoba dan tidak perlu diulang.
     */
    private function pelatihan(): void
    {
        Schema::create('pelatihan_model_relevansi', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->string('base_model', 200);
            $table->foreignId('snapshot_dataset_relevansi_id')
                ->constrained('snapshot_dataset_relevansi')->restrictOnDelete();
            $table->foreignId('versi_konteks_relevansi_id')
                ->constrained('versi_konteks_relevansi')->restrictOnDelete();
            $table->string('versi_panduan_label', 20);
            $table->foreignId('parent_run_id')->nullable()
                ->constrained('pelatihan_model_relevansi')->nullOnDelete();
            $table->string('status', 30)->default('menunggu');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->unsignedSmallInteger('current_epoch')->nullable();
            $table->unsignedInteger('current_step')->nullable();
            $table->unsignedInteger('total_steps')->nullable();
            $table->jsonb('configuration');
            $table->jsonb('runtime_info')->nullable();
            $table->jsonb('metrics_validation')->nullable();
            $table->jsonb('metrics_test')->nullable();
            $table->jsonb('artifact_manifest')->nullable();
            $table->text('artifact_path')->nullable();
            $table->text('error_summary')->nullable();
            $table->text('error_trace_path')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE pelatihan_model_relevansi ADD CONSTRAINT chk_pelatihan_status
            CHECK (status IN ('menunggu','validasi_data','mengekspor_dataset','mempersiapkan_model',
                'melatih','validasi_epoch','memilih_checkpoint','mengevaluasi_test','menyimpan_artefak',
                'selesai','gagal','dibatalkan'))");
    }

    /**
     * Versi model beserta status dan checksum artefaknya.
     *
     * Checksum bukan hiasan: artefak yang berubah tanpa sepengetahuan sistem
     * berarti model yang berjalan bukan model yang dievaluasi, dan seluruh
     * angka gerbang mutu menjadi klaim tentang berkas yang sudah tidak ada.
     */
    private function versiModel(): void
    {
        Schema::create('versi_model_relevansi', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150);
            $table->string('versi', 40)->unique();
            $table->string('base_model', 200);
            $table->foreignId('pelatihan_model_relevansi_id')->nullable()
                ->constrained('pelatihan_model_relevansi')->nullOnDelete();
            $table->foreignId('snapshot_dataset_relevansi_id')->nullable()
                ->constrained('snapshot_dataset_relevansi')->nullOnDelete();
            $table->foreignId('versi_threshold_relevansi_id')->nullable()
                ->constrained('versi_threshold_relevansi')->nullOnDelete();
            $table->foreignId('versi_konteks_relevansi_id')
                ->constrained('versi_konteks_relevansi')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->text('artifact_path')->nullable();
            $table->string('artifact_checksum', 64)->nullable();
            $table->jsonb('metrics')->nullable();
            $table->jsonb('runtime_info')->nullable();
            $table->string('quality_gate_status', 20)->default('blocked');
            $table->jsonb('quality_gate_report')->nullable();
            $table->foreignId('promoted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('promotion_reason')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE versi_model_relevansi ADD CONSTRAINT chk_versi_model_status
            CHECK (status IN ('draft','training','candidate','production','archived','failed','rejected'))");
        DB::statement("ALTER TABLE versi_model_relevansi ADD CONSTRAINT chk_versi_model_gerbang
            CHECK (quality_gate_status IN ('blocked','needs_review','passed','revoked'))");

        // Tepat satu model produksi, ditegakkan database dan bukan hanya kode.
        // Promosi berjalan lewat beberapa langkah, dan satu langkah yang gagal
        // di tengah tanpa penjaga ini meninggalkan dua model produksi
        // sekaligus. Yang dipakai lalu bergantung urutan baris, artinya hasil
        // analisis berubah tanpa ada yang mengubah apa pun.
        DB::statement("CREATE UNIQUE INDEX uq_model_relevansi_produksi
            ON versi_model_relevansi ((status)) WHERE status = 'production'");

        // Model produksi tanpa artefak dan checksum tidak bisa diverifikasi
        // maupun dimuat ulang setelah restart.
        DB::statement("ALTER TABLE versi_model_relevansi ADD CONSTRAINT chk_versi_model_produksi_lengkap
            CHECK (status <> 'production' OR (artifact_path IS NOT NULL
                AND artifact_checksum IS NOT NULL AND versi_threshold_relevansi_id IS NOT NULL))");
    }

    /**
     * Riwayat setiap prediksi. Tidak pernah ditimpa.
     *
     * Probabilitas mentah disimpan, bukan hanya labelnya. Itu yang membuat
     * penyetelan ambang tidak memerlukan inferensi ulang, sifat yang paling
     * berharga dari rancangan sebelumnya dan sengaja dipertahankan.
     */
    private function prediksi(): void
    {
        Schema::create('prediksi_relevansi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sampel_relevansi_id')->nullable()
                ->constrained('sampel_relevansi')->cascadeOnDelete();
            $table->foreignId('artikel_id')->nullable()->constrained('artikel')->cascadeOnDelete();
            $table->foreignId('versi_model_relevansi_id')
                ->constrained('versi_model_relevansi')->cascadeOnDelete();
            $table->foreignId('versi_threshold_relevansi_id')->nullable()
                ->constrained('versi_threshold_relevansi')->nullOnDelete();
            $table->foreignId('versi_konteks_relevansi_id')
                ->constrained('versi_konteks_relevansi')->restrictOnDelete();
            $table->string('label_prediksi', 20);
            $table->float('probabilitas_relevan');
            $table->float('probabilitas_tidak_relevan');
            $table->float('confidence');
            $table->boolean('review_required')->default(false);
            $table->string('input_hash', 64);
            $table->unsignedSmallInteger('input_tokens')->nullable();
            $table->boolean('input_truncated')->default(false);
            $table->unsignedInteger('inference_ms')->nullable();
            $table->jsonb('sinyal')->nullable();
            $table->timestampTz('predicted_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['artikel_id', 'predicted_at'], 'idx_prediksi_artikel_waktu');
            $table->index(['versi_model_relevansi_id', 'label_prediksi'], 'idx_prediksi_model_label');
            $table->index('sampel_relevansi_id');
        });

        DB::statement("ALTER TABLE prediksi_relevansi ADD CONSTRAINT chk_prediksi_label
            CHECK (label_prediksi IN ('relevan','tidak_relevan'))");
    }

    /**
     * Hasil evaluasi per pasangan model, snapshot, dan ambang.
     *
     * `configuration_hash` unik supaya konfigurasi identik tidak pernah punya
     * dua baris metrik. Dua angka berbeda untuk konfigurasi yang sama berarti
     * salah satunya keliru, dan tidak ada cara mengetahui yang mana.
     */
    private function evaluasi(): void
    {
        Schema::create('evaluasi_model_relevansi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('versi_model_relevansi_id')
                ->constrained('versi_model_relevansi')->cascadeOnDelete();
            $table->foreignId('snapshot_dataset_relevansi_id')
                ->constrained('snapshot_dataset_relevansi')->restrictOnDelete();
            $table->foreignId('versi_threshold_relevansi_id')
                ->constrained('versi_threshold_relevansi')->restrictOnDelete();
            $table->string('configuration_hash', 64)->unique();
            $table->jsonb('metrics');
            $table->jsonb('confusion_matrix');
            $table->jsonb('classification_report');
            $table->jsonb('per_source_metrics')->nullable();
            $table->jsonb('per_group_metrics')->nullable();
            $table->jsonb('error_analysis')->nullable();
            $table->string('status', 20)->default('berjalan');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('versi_model_relevansi_id');
        });

        DB::statement("ALTER TABLE evaluasi_model_relevansi ADD CONSTRAINT chk_evaluasi_status
            CHECK (status IN ('berjalan','selesai','gagal'))");
    }

    /**
     * Status gerbang mutu per versi model.
     *
     * Standar yang berlaku saat penilaian ikut disimpan, bukan dibaca dari
     * pengaturan saat laporan dibuka. Standar bisa berubah, dan laporan yang
     * berubah artinya setelah standarnya diturunkan adalah laporan yang tidak
     * membuktikan apa pun.
     */
    private function gerbangMutu(): void
    {
        Schema::create('gerbang_mutu_relevansi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('versi_model_relevansi_id')
                ->constrained('versi_model_relevansi')->cascadeOnDelete();
            $table->string('status', 20)->default('blocked');
            $table->jsonb('standar');
            $table->jsonb('hasil');
            $table->jsonb('failed_checks')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestampsTz();

            $table->index(['versi_model_relevansi_id', 'status']);
        });

        DB::statement("ALTER TABLE gerbang_mutu_relevansi ADD CONSTRAINT chk_gerbang_status
            CHECK (status IN ('blocked','needs_review','passed','revoked'))");
        // Pencabutan tanpa alasan tidak bisa ditinjau, dan gerbang yang dicabut
        // diam-diam terlihat sama persis dengan gerbang yang belum pernah lulus.
        DB::statement("ALTER TABLE gerbang_mutu_relevansi ADD CONSTRAINT chk_gerbang_pencabutan_beralasan
            CHECK (status <> 'revoked' OR revocation_reason IS NOT NULL)");
    }

    /**
     * Riwayat pengujian manual di tab Uji Model.
     *
     * Prediksi di sini tidak pernah mengubah artikel produksi. Halaman uji
     * adalah tempat mencoba, dan tempat mencoba yang diam-diam menulis ke
     * produksi berhenti menjadi tempat mencoba.
     */
    private function ujiManual(): void
    {
        Schema::create('uji_manual_relevansi', function (Blueprint $table) {
            $table->id();
            $table->string('tipe_input', 20);
            $table->text('url')->nullable();
            $table->text('judul')->nullable();
            $table->text('excerpt')->nullable();
            $table->text('isi')->nullable();
            $table->jsonb('extracted_metadata')->nullable();
            $table->foreignId('versi_model_relevansi_id')
                ->constrained('versi_model_relevansi')->cascadeOnDelete();
            $table->jsonb('hasil_prediksi');
            $table->string('feedback_label', 20)->nullable();
            $table->string('feedback_reason', 40)->nullable();
            $table->foreignId('saved_as_sample_id')->nullable()
                ->constrained('sampel_relevansi')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("ALTER TABLE uji_manual_relevansi ADD CONSTRAINT chk_uji_tipe
            CHECK (tipe_input IN ('url','manual_text','dataset'))");
        DB::statement("ALTER TABLE uji_manual_relevansi ADD CONSTRAINT chk_uji_feedback
            CHECK (feedback_label IS NULL OR feedback_label IN ('relevan','tidak_relevan'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('uji_manual_relevansi');
        Schema::dropIfExists('gerbang_mutu_relevansi');
        Schema::dropIfExists('evaluasi_model_relevansi');
        Schema::dropIfExists('prediksi_relevansi');
        Schema::dropIfExists('versi_model_relevansi');
        Schema::dropIfExists('pelatihan_model_relevansi');
        Schema::dropIfExists('item_snapshot_dataset_relevansi');
        Schema::dropIfExists('snapshot_dataset_relevansi');
        Schema::dropIfExists('sampel_relevansi');
        Schema::dropIfExists('versi_threshold_relevansi');
        Schema::dropIfExists('versi_konteks_relevansi');
    }
};
