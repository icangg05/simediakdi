<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perkiraan sisa waktu pelatihan, dilaporkan layanan model.
 *
 * Kolom tersendiri, bukan diselipkan ke dalam `metrik` atau `riwayat_epoch`.
 * Keduanya jsonb hasil evaluasi yang berhenti berubah setelah pelatihan
 * selesai, sedangkan angka ini justru hanya berarti selama pelatihan berjalan
 * dan ditimpa tiap beberapa detik. Menaruhnya di sana berarti kolom hasil ikut
 * ditulis ulang ratusan kali untuk sesuatu yang bukan hasil.
 *
 * Nullable, dan itu bukan kelalaian. Selama tahap memuat base model tidak ada
 * satuan yang bisa dihitung: mengunduh 1,3 GB dan memuat dari cache berbeda dua
 * orde besaran. Kosong berarti belum bisa dihitung, dan layar memang harus
 * mengatakan begitu alih-alih menampilkan tebakan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pelatihan_model_relevansi', function (Blueprint $table) {
            $table->unsignedInteger('estimasi_sisa_detik')->nullable()->after('epoch_berjalan');
        });
    }

    public function down(): void
    {
        Schema::table('pelatihan_model_relevansi', function (Blueprint $table) {
            $table->dropColumn('estimasi_sisa_detik');
        });
    }
};
