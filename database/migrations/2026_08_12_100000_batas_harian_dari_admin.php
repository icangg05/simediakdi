<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batas kuota harian yang diisi admin, untuk kunci yang tiernya bukan free.
 *
 * Kolom `rpd_google` sudah menyimpan batas yang disebut Google sendiri, tetapi
 * ia hanya bisa terisi lewat satu jalan: badan galat 429 harian. Di situlah
 * letak jebakannya. Penjaga kuota lokal menahan kunci begitu pemakaian
 * menyentuh angka config, jadi permintaan yang akan memicu 429 itu tidak pernah
 * dikirim, jadi Google tidak pernah menyebut angkanya, jadi kolomnya tidak
 * pernah terisi.
 *
 * Untuk kunci free tier itu tidak masalah, karena tebakan config memang sama
 * dengan kenyataannya. Untuk kunci berbayar akibatnya permanen: jatah 10.000
 * per hari dipotong menjadi 500 selamanya, dan tidak ada satu pun galat yang
 * menunjukkannya. Yang terlihat hanya antrean yang berjalan pelan.
 *
 * Kolom ini memutus lingkaran itu dengan cara paling sederhana: admin yang tahu
 * tier kuncinya mengetikkan angkanya sendiri. Sengaja dipisah dari
 * `rpd_google`, bukan menumpang di sana, karena keduanya beda derajat. Yang
 * satu fakta dari Google, yang satu keterangan dari manusia yang bisa salah
 * ketik. Menyatukannya berarti angka ketikan tampil di layar dengan wibawa yang
 * sama seperti angka resmi, dan halaman ini sudah pernah memutuskan bahwa
 * tebakan tidak boleh ditulis setegas fakta.
 *
 * Urutan pemakaiannya ada di RotasiKunciGemini::batasHarian(): Google menang,
 * lalu admin, lalu config. Google menang karena kalau admin mengisi terlalu
 * besar, 429 harian akan benar-benar terjadi dan angka aslinya masuk sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kunci_gemini', function (Blueprint $tabel) {
            $tabel->unsignedInteger('rpd_manual')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kunci_gemini', function (Blueprint $tabel) {
            $tabel->dropColumn('rpd_manual');
        });
    }
};
