<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empat tabel halaman Model Relevansi.
 *
 * Ini bukan pemulihan laboratorium IndoBERT yang dibongkar migration
 * 2026_08_05_120000. Yang dulu ada sebelas tabel dan berdiri di atas pelabelan
 * manual: sampel, gold set, versi konteks, versi ambang, gerbang mutu. Semua
 * itu ada karena labelnya harus dibuat manusia satu per satu.
 *
 * Sekarang labelnya sudah ada. Gemini menilai setiap artikel, hasilnya duduk di
 * `analisis_sentimen.relevan`, dan 1.673 baris di antaranya sudah punya
 * keputusan yang pasti. Yang tersisa hanya tiga pertanyaan: data mana yang
 * dipakai, model apa yang dilatih di atasnya, dan seberapa benar hasilnya.
 * Empat tabel menjawab ketiganya.
 *
 * Nama tabelnya sengaja sama dengan empat tabel lama yang sudah di-drop. Nama
 * itu memang nama yang benar untuk isinya, dan membuat `snapshot_dataset_v2`
 * hanya menandai bahwa ada versi pertama yang masih hidup di suatu tempat.
 * Tidak ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->snapshot();
        $this->itemSnapshot();
        $this->pelatihan();
        $this->ujiManual();
    }

    /**
     * Resep satu dataset beku beserta angka hasilnya.
     *
     * Komposisi dan rasio split disimpan sebagai persen, bukan hanya hasil
     * akhirnya. Tanpa resepnya, snapshot yang menghasilkan model buruk tidak
     * bisa dibedakan dari snapshot yang menghasilkan model baik, dan tidak ada
     * yang bisa mengulang percobaan dengan satu angka diubah.
     */
    private function snapshot(): void
    {
        Schema::create('snapshot_dataset_relevansi', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150)->unique();
            $table->text('deskripsi')->nullable();

            // Seed yang benar-benar dipakai, bukan yang diminta. Kolom ini
            // satu-satunya alasan pengambilan acak bisa diulang, jadi ia diisi
            // sendiri oleh sistem kalau pengguna mengosongkannya.
            $table->integer('random_seed');

            $table->unsignedSmallInteger('persen_relevan');
            $table->unsignedSmallInteger('persen_tidak_relevan');
            $table->unsignedSmallInteger('persen_train');
            $table->unsignedSmallInteger('persen_validation');
            $table->unsignedSmallInteger('persen_test');

            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('total_relevan')->default(0);
            $table->unsignedInteger('total_tidak_relevan')->default(0);
            $table->unsignedInteger('total_train')->default(0);
            $table->unsignedInteger('total_validation')->default(0);
            $table->unsignedInteger('total_test')->default(0);

            $table->string('status', 20)->default('siap');
            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE snapshot_dataset_relevansi ADD CONSTRAINT chk_snapshot_status
            CHECK (status IN ('siap','terpakai','arsip'))");
        DB::statement('ALTER TABLE snapshot_dataset_relevansi ADD CONSTRAINT chk_snapshot_komposisi
            CHECK (persen_relevan + persen_tidak_relevan = 100)');
        DB::statement('ALTER TABLE snapshot_dataset_relevansi ADD CONSTRAINT chk_snapshot_pembagian
            CHECK (persen_train + persen_validation + persen_test = 100)');
    }

    /**
     * Isi snapshot, lengkap dengan teks artikelnya.
     *
     * Judul dan isi disalin, bukan dibaca lewat join ke `artikel`. Ini
     * penggandaan data yang disengaja, dan alasannya ada tiga. Crawler menimpa
     * `artikel.isi` setiap kali halaman yang sama diambil ulang. Penilaian
     * Gemini bisa dijalankan ulang dan mengubah `analisis_sentimen.relevan`.
     * Artikel bisa dibuang lewat halaman Artikel. Ketiganya diam-diam mengubah
     * arti angka evaluasi model yang sudah selesai dilatih, dan model yang
     * angkanya berubah setelah pelatihan selesai tidak membuktikan apa pun.
     *
     * `artikel_id` tetap disimpan supaya barisnya bisa ditelusuri balik, tetapi
     * ia nullable dan diputus saat artikelnya dihapus. Snapshot tidak menahan
     * artikel agar tidak bisa dibuang.
     */
    private function itemSnapshot(): void
    {
        Schema::create('item_snapshot_relevansi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_dataset_relevansi_id')
                ->constrained('snapshot_dataset_relevansi')->cascadeOnDelete();
            $table->foreignId('artikel_id')->nullable()->constrained('artikel')->nullOnDelete();
            $table->text('judul');
            $table->text('isi');
            $table->string('label', 20);
            $table->string('split', 15);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['snapshot_dataset_relevansi_id', 'split'], 'idx_item_snapshot_split');
        });

        // Satu artikel satu baris per snapshot. Duplikat membuat satu artikel
        // dihitung dua kali dalam metrik, dan lebih buruk lagi, bisa muncul di
        // train sekaligus test sehingga akurasinya terbaca lebih tinggi
        // daripada yang sebenarnya.
        DB::statement('CREATE UNIQUE INDEX uq_item_snapshot_artikel
            ON item_snapshot_relevansi (snapshot_dataset_relevansi_id, artikel_id)
            WHERE artikel_id IS NOT NULL');

        DB::statement("ALTER TABLE item_snapshot_relevansi ADD CONSTRAINT chk_item_label
            CHECK (label IN ('relevan','tidak_relevan'))");
        DB::statement("ALTER TABLE item_snapshot_relevansi ADD CONSTRAINT chk_item_split
            CHECK (split IN ('train','validation','test'))");
    }

    /**
     * Satu baris per pelatihan, dari saat tombolnya ditekan sampai artefaknya
     * tersimpan.
     *
     * Baris yang sama menampung status berjalan dan hasil akhir. Memisahkannya
     * menjadi tabel "run" dan tabel "hasil" berarti halaman pemantauan harus
     * menggabungkan dua sumber untuk menjawab satu pertanyaan, dan pelatihan
     * yang gagal di tengah meninggalkan baris di satu tabel tanpa pasangan di
     * tabel lain.
     */
    private function pelatihan(): void
    {
        Schema::create('pelatihan_model_relevansi', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150)->unique();
            $table->foreignId('snapshot_dataset_relevansi_id')
                ->constrained('snapshot_dataset_relevansi')->restrictOnDelete();
            $table->string('base_model', 60);
            $table->jsonb('konfigurasi');

            $table->string('status', 20)->default('menunggu');
            // Tahap yang sedang dikerjakan, ditulis apa adanya untuk layar.
            // Terpisah dari `status` karena status hanya lima nilai dan
            // dipakai penyaringan, sedangkan tahap adalah kalimat.
            $table->string('tahap', 60)->nullable();
            $table->unsignedSmallInteger('progres')->default(0);
            $table->unsignedSmallInteger('epoch_berjalan')->nullable();

            // Diminta lewat tombol Batalkan. Job memeriksanya di sela epoch,
            // karena membunuh proses di tengah tulisan artefak meninggalkan
            // berkas separuh yang terlihat seperti model utuh.
            $table->boolean('batal_diminta')->default(false);

            $table->jsonb('metrik')->nullable();
            $table->jsonb('riwayat_epoch')->nullable();
            $table->jsonb('confusion_matrix')->nullable();
            $table->jsonb('laporan_klasifikasi')->nullable();
            $table->text('artefak_path')->nullable();
            $table->string('perangkat', 60)->nullable();
            $table->text('galat')->nullable();

            $table->boolean('aktif')->default(false);
            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnDelete();
            $table->timestampTz('mulai_at')->nullable();
            $table->timestampTz('selesai_at')->nullable();
            $table->timestampsTz();

            $table->index('status');
        });

        DB::statement("ALTER TABLE pelatihan_model_relevansi ADD CONSTRAINT chk_pelatihan_status
            CHECK (status IN ('menunggu','berjalan','berhasil','gagal','dibatalkan'))");

        // Model aktif tanpa artefak adalah model yang tidak bisa dimuat, dan
        // pelatihan yang belum berhasil tidak punya artefak sama sekali.
        DB::statement("ALTER TABLE pelatihan_model_relevansi ADD CONSTRAINT chk_pelatihan_aktif_lengkap
            CHECK (aktif = false OR (status = 'berhasil' AND artefak_path IS NOT NULL))");

        // Tepat satu model aktif, ditegakkan database dan bukan hanya kode.
        // Penetapan berjalan lewat dua tulisan, mencabut yang lama dan memasang
        // yang baru, dan satu tulisan yang gagal di tengah tanpa penjaga ini
        // meninggalkan dua model aktif sekaligus.
        DB::statement('CREATE UNIQUE INDEX uq_pelatihan_aktif
            ON pelatihan_model_relevansi ((aktif)) WHERE aktif = true');
    }

    /**
     * Riwayat tab Pengujian Model.
     *
     * Teks yang diuji disimpan utuh, bukan potongannya saja. Yang berguna dari
     * riwayat pengujian adalah bisa menjalankan teks yang sama pada model
     * berikutnya dan membandingkan jawabannya, dan potongan 200 karakter tidak
     * bisa dijalankan ulang.
     */
    private function ujiManual(): void
    {
        Schema::create('uji_manual_relevansi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pelatihan_model_relevansi_id')
                ->constrained('pelatihan_model_relevansi')->cascadeOnDelete();
            $table->text('teks');
            $table->string('label_prediksi', 20);
            $table->float('probabilitas_relevan');
            $table->float('probabilitas_tidak_relevan');
            $table->float('confidence');
            $table->unsignedInteger('inferensi_ms');
            $table->foreignId('dibuat_oleh')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
        });

        DB::statement("ALTER TABLE uji_manual_relevansi ADD CONSTRAINT chk_uji_label
            CHECK (label_prediksi IN ('relevan','tidak_relevan'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('uji_manual_relevansi');
        Schema::dropIfExists('pelatihan_model_relevansi');
        Schema::dropIfExists('item_snapshot_relevansi');
        Schema::dropIfExists('snapshot_dataset_relevansi');
    }
};
