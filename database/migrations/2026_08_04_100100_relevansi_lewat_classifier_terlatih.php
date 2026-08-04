<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relevansi berpindah dari kemiripan makna ke classifier hasil fine-tuning.
 *
 * Kebalikan arah dari migration sebelumnya, dan itu bukan karena kesimpulannya
 * salah. Cosine memang menaikkan presisi dari 57,0% ke 69,9%, lalu berhenti di
 * sana. Yang menahannya bukan pilihan model melainkan dataset: 249 label,
 * dibuat dengan aturan tiga konteks yang sudah tidak berlaku, tanpa data tahan.
 *
 * Yang dulu diukur adalah checkpoint IndoBERT bawaan tanpa pelatihan, dan itu
 * memang hanya menambah satu poin di atas aturan kata kunci. Yang dipakai
 * sekarang adalah checkpoint yang sama setelah dilatih dengan label Kendari.
 * Keduanya bukan hal yang sama.
 *
 * Dokumen 10 bagian 0, dokumen 05 bagian 2, dokumen 03 changelog 1.6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analisis_sentimen', function (Blueprint $table) {
            // Model mana yang memutuskan baris ini. Selama relevansi berupa
            // ambang global di `.env`, jawabannya ada di git dan itu cukup.
            // Begitu ada beberapa versi model yang bergantian menjadi produksi,
            // keputusan tanpa penunjuk versi tidak bisa ditelusuri asalnya.
            $table->foreignId('versi_model_relevansi_id')->nullable()
                ->constrained('versi_model_relevansi')->nullOnDelete();
            $table->foreignId('versi_threshold_relevansi_id')->nullable()
                ->constrained('versi_threshold_relevansi')->nullOnDelete();
        });

        // Artikel yang tertahan karena belum ada model relevansi produksi yang
        // lolos gerbang mutu. Bukan galat, melainkan keadaan awal seluruh
        // artikel baru sampai laboratorium menghasilkan model yang lulus.
        //
        // Kolomnya ikut dilebarkan: `model_belum_lulus_gate` 22 karakter,
        // sedangkan varchar(20) memotongnya. Postgres menolak, bukan memotong
        // diam-diam, jadi kegagalannya terlihat, tetapi tetap harus dibetulkan
        // di sini dan bukan dengan memendekkan nama statusnya.
        DB::statement('ALTER TABLE artikel ALTER COLUMN status_proses TYPE varchar(30)');
        DB::statement('ALTER TABLE artikel DROP CONSTRAINT chk_artikel_status_proses');
        DB::statement("ALTER TABLE artikel ADD CONSTRAINT chk_artikel_status_proses
            CHECK (status_proses IN ('mentah','isi_diambil','dianalisis','perlu_review',
                'tidak_relevan','model_belum_lulus_gate','selesai','gagal'))");

        $this->pindahkanLabelGoldSet();

        // Dua vektor menjadi satu. Yang tersisa, `artikel.embedding`, tetap
        // untuk deteksi salinan dan tidak disentuh sama sekali.
        Schema::table('artikel', function (Blueprint $table) {
            $table->dropColumn('embedding_relevansi');
        });

        Schema::table('konteks_pantauan', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });
    }

    /**
     * 249 keputusan manusia dipindahkan, bukan ditinggalkan.
     *
     * Hanya ronde 1 dan hanya konteks utama. Ronde 2 adalah pelabelan ulang
     * atas artikel yang sama untuk mengukur konsistensi pelabel, jadi
     * memindahkannya sekaligus akan menabrak unique index satu artikel satu
     * sampel. Dua konteks lama sudah dihapus pada sprint 6 dan labelnya
     * diarsipkan ke storage.
     *
     * `last_reviewed_at` sengaja dibiarkan NULL. Label ini dibuat di bawah
     * panduan versi 2.0 dan sebagian keputusannya bisa berubah di bawah kode
     * alasan yang sekarang. Filter "belum pernah direview ulang" di tab dataset
     * adalah yang memunculkannya kembali untuk ditinjau.
     */
    private function pindahkanLabelGoldSet(): void
    {
        if (! Schema::hasTable('gold_set')) {
            return;
        }

        DB::statement("
            INSERT INTO sampel_relevansi (
                artikel_id, sumber_dataset, judul, excerpt, isi, url, media_id,
                tanggal_publikasi, metadata_sumber,
                label_manual, status_label, duplicate_group_id,
                labeled_by, labeled_at, created_at, updated_at
            )
            SELECT
                a.id,
                'migrated_gold_set',
                a.judul,
                a.ringkasan,
                COALESCE(a.isi, ''),
                a.url,
                a.media_id,
                a.dipublikasikan_at,
                jsonb_build_object(
                    'gold_set_id', g.id,
                    'versi_panduan_label', '2.0',
                    'ronde', g.ronde,
                    'catatan', g.catatan
                ),
                CASE WHEN g.relevan_gold THEN 'relevan' ELSE 'tidak_relevan' END,
                'sudah_dilabeli',
                COALESCE(a.artikel_induk_id, a.id),
                g.dilabeli_oleh,
                g.dilabeli_at,
                now(),
                now()
            FROM gold_set g
            JOIN artikel a ON a.id = g.artikel_id
            JOIN konteks_pantauan k ON k.id = g.konteks_pantauan_id AND k.utama = true
            WHERE g.ronde = 1
              AND a.isi IS NOT NULL
            ON CONFLICT DO NOTHING
        ");
    }

    public function down(): void
    {
        Schema::table('konteks_pantauan', function (Blueprint $table) {
            $table->vector('embedding', 384)->nullable();
        });

        Schema::table('artikel', function (Blueprint $table) {
            $table->vector('embedding_relevansi', 384)->nullable();
        });

        // Sampel hasil pemindahan dibuang, sumber aslinya di `gold_set` tidak
        // pernah disentuh. Sampel berlabel manusia lain dibiarkan: menghapusnya
        // berarti membuang pekerjaan pelabelan yang tidak ada salinannya.
        DB::statement("DELETE FROM sampel_relevansi WHERE sumber_dataset = 'migrated_gold_set'");

        DB::statement("UPDATE artikel SET status_proses = 'perlu_review'
            WHERE status_proses = 'model_belum_lulus_gate'");
        DB::statement('ALTER TABLE artikel DROP CONSTRAINT chk_artikel_status_proses');
        DB::statement('ALTER TABLE artikel ALTER COLUMN status_proses TYPE varchar(20)');
        DB::statement("ALTER TABLE artikel ADD CONSTRAINT chk_artikel_status_proses
            CHECK (status_proses IN ('mentah','isi_diambil','dianalisis','perlu_review','tidak_relevan','selesai','gagal'))");

        Schema::table('analisis_sentimen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('versi_model_relevansi_id');
            $table->dropConstrainedForeignId('versi_threshold_relevansi_id');
        });
    }
};
