<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membongkar seluruh laboratorium relevansi IndoBERT.
 *
 * Sistem berpindah sepenuhnya ke Gemini. Yang dibuang di sini bukan hanya
 * model, melainkan seluruh perangkat yang ada untuk melatih dan mengukurnya:
 * dataset berlabel, snapshot beserta split-nya, riwayat pelatihan, versi model,
 * versi ambang, versi konteks, gerbang mutu, dan riwayat prediksi.
 *
 * MIGRATION INI MENGHAPUS PEKERJAAN MANUSIA. `sampel_relevansi` memuat 582
 * label buatan tangan dan `gold_set` memuat 250. Keduanya sudah diekspor lebih
 * dulu ke `storage/app/private/arsip-label-indobert.json`, lengkap dengan teks
 * artikelnya sehingga berkas itu berdiri sendiri. Berkas itu satu-satunya
 * salinan. Jangan jalankan migration ini di lingkungan mana pun yang belum
 * punya berkas tersebut.
 *
 * `down()` sengaja tidak membangun ulang apa pun. Menulis sebelas definisi
 * tabel yang tidak akan pernah dipakai lagi hanya menciptakan kesan bahwa
 * pembongkaran ini bisa dibatalkan. Ia tidak bisa: datanya ada di JSON, bukan
 * di skema.
 */
return new class extends Migration
{
    /**
     * Urutannya mengikuti arah foreign key, dari anak ke induk.
     *
     * Postgres akan menolak sendiri kalau salah urutan, jadi ini bukan
     * pengaman. Yang dijaga adalah keterbacaan: daftar yang terurut menunjukkan
     * bentuk ketergantungan laboratorium ini sekali lihat.
     */
    private const TABEL = [
        'prediksi_relevansi',
        'evaluasi_model_relevansi',
        'gerbang_mutu_relevansi',
        'uji_manual_relevansi',
        'item_snapshot_dataset_relevansi',
        'versi_model_relevansi',
        'pelatihan_model_relevansi',
        'snapshot_dataset_relevansi',
        'sampel_relevansi',
        'versi_threshold_relevansi',
        'versi_konteks_relevansi',
        'gold_set',
        'evaluasi_model',
    ];

    public function up(): void
    {
        Schema::table('analisis_sentimen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('versi_model_relevansi_id');
            $table->dropConstrainedForeignId('versi_threshold_relevansi_id');

            // Isinya probabilitas IndoBERT. Gemini tidak mengeluarkan angka
            // semacam itu, jadi kolom ini hanya akan berisi nilai lama yang
            // tidak pernah bertambah, lalu suatu hari dibaca sebagai skor yang
            // masih berlaku.
            $table->dropColumn('skor_relevansi');
        });

        foreach (self::TABEL as $tabel) {
            Schema::dropIfExists($tabel);
        }

        // `model_belum_lulus_gate` kehilangan artinya bersama gerbang mutu.
        // Artikel yang masih memakainya dikembalikan ke `isi_diambil`, yaitu
        // keadaan yang sebenarnya: isinya sudah ada, belum diklasifikasi, dan
        // menunggu di halaman Antrean Klasifikasi.
        DB::statement("UPDATE artikel SET status_proses = 'isi_diambil'
            WHERE status_proses = 'model_belum_lulus_gate'");

        DB::statement('ALTER TABLE artikel DROP CONSTRAINT chk_artikel_status_proses');
        DB::statement("ALTER TABLE artikel ADD CONSTRAINT chk_artikel_status_proses
            CHECK (status_proses IN ('mentah','isi_diambil','dianalisis','perlu_review',
                'tidak_relevan','selesai','gagal'))");
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Pembongkaran laboratorium IndoBERT tidak bisa dibatalkan lewat migration. '
            .'Label manusianya ada di storage/app/private/arsip-label-indobert.json, '
            .'dan skemanya harus dibangun ulang dari riwayat git bila benar-benar dibutuhkan.'
        );
    }
};
