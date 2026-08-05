<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membuang konteks pantauan dan entitas.
 *
 * **Konteks pantauan** dirancang untuk memantau beberapa subjek sekaligus.
 * Selama sistem berjalan isinya tidak pernah lebih dari satu baris, dan
 * barisnya tidak pernah berubah. Yang tersisa hanyalah biayanya: kolom
 * `konteks_pantauan_id` di empat tabel, parameter `konteks` di setiap URL
 * eksekutif, dan dropdown pemilih berisi satu pilihan di lima halaman.
 * Definisinya pindah ke `config/pantauan.php`.
 *
 * **Entitas** menghitung sebutan nama pejabat dan OPD di setiap artikel, 6.841
 * baris pivot, dan tidak satu pun halaman pernah membacanya selain halaman
 * entitas itu sendiri. Ia tidak masuk dashboard, tidak masuk alert, dan tidak
 * dipakai Gemini. Fitur yang menghitung angka yang tidak dilihat siapa pun
 * tetap memakan waktu scheduler dan tetap harus ikut dipahami setiap kali
 * seseorang membaca kode ini.
 *
 * Baris `ringkasan_harian` dan `kata_kunci_periode` yang terikat konteks
 * dibuang, bukan digabung. Keduanya cache hasil agregasi yang bisa dihitung
 * ulang penuh dari artikel lewat `hitung:ringkasan-harian` dan
 * `hitung:kata-kunci`. Menggabungkannya dengan penjumlahan justru berisiko:
 * satu artikel bisa terhitung dua kali di baris lintas konteks.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cache agregasi dikosongkan lebih dulu. Kunci uniknya memuat
        // `konteks_pantauan_id`, jadi membuang kolomnya sementara barisnya
        // tetap ada akan meninggalkan duplikat yang menolak index baru.
        DB::table('ringkasan_harian')->delete();
        DB::table('kata_kunci_periode')->delete();

        Schema::table('ringkasan_harian', function (Blueprint $table) {
            $table->dropConstrainedForeignId('konteks_pantauan_id');
        });

        Schema::table('kata_kunci_periode', function (Blueprint $table) {
            $table->dropConstrainedForeignId('konteks_pantauan_id');
        });

        Schema::table('aturan_alert', function (Blueprint $table) {
            $table->dropConstrainedForeignId('konteks_pantauan_id');
        });

        $this->analisisSentimen();

        DB::statement('DROP INDEX IF EXISTS uq_kata_kunci_periode');
        DB::statement('CREATE UNIQUE INDEX uq_kata_kunci_periode
            ON kata_kunci_periode (granularitas, periode_mulai, istilah)');

        DB::statement('DROP INDEX IF EXISTS uq_ringkasan_harian');
        DB::statement('CREATE UNIQUE INDEX uq_ringkasan_harian
            ON ringkasan_harian (tanggal, media_id) NULLS NOT DISTINCT');

        Schema::dropIfExists('artikel_entitas');
        Schema::dropIfExists('entitas');
        Schema::dropIfExists('konteks_pantauan');
    }

    /**
     * Satu artikel satu baris analisis, bukan satu per konteks.
     *
     * Artikel yang punya lebih dari satu baris dirapikan lebih dulu. Yang
     * dipertahankan baris dengan koreksi manusia bila ada, karena itulah satu
     * satunya isi yang tidak bisa dihitung ulang. Sisanya hasil model yang
     * cukup ditekan tombolnya sekali lagi.
     */
    private function analisisSentimen(): void
    {
        DB::statement('
            DELETE FROM analisis_sentimen a
            USING analisis_sentimen b
            WHERE a.artikel_id = b.artikel_id
              AND a.id <> b.id
              AND (
                    (b.label_manual IS NOT NULL AND a.label_manual IS NULL)
                 OR (b.relevan_manual IS NOT NULL AND a.relevan_manual IS NULL)
                 OR ((b.label_manual IS NULL) = (a.label_manual IS NULL)
                     AND (b.relevan_manual IS NULL) = (a.relevan_manual IS NULL)
                     AND b.id > a.id)
              )
        ');

        DB::statement('DROP INDEX IF EXISTS uq_analisis_artikel_konteks');
        DB::statement('DROP INDEX IF EXISTS idx_analisis_konteks_label');

        Schema::table('analisis_sentimen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('konteks_pantauan_id');
        });

        DB::statement('CREATE UNIQUE INDEX uq_analisis_artikel ON analisis_sentimen (artikel_id)');
        DB::statement('CREATE INDEX idx_analisis_label
            ON analisis_sentimen (label_efektif) WHERE relevan = true');
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Penghapusan konteks pantauan dan entitas tidak bisa dibatalkan lewat migration. '
            .'Definisi konteks sekarang ada di config/pantauan.php, dan skema lamanya '
            .'harus dibangun ulang dari riwayat git bila benar-benar dibutuhkan.'
        );
    }
};
