<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kredensial Telegram pindah dari `.env` ke database, satu arah dan seluruhnya.
 *
 * Migrasi `2026_08_06_090000` menempatkan kredensial alert di daftar yang
 * sengaja tidak dipindahkan, dengan alasan ia jarang berubah dan perubahannya
 * perlu tercatat di git. Alasan itu tidak bertahan di pemakaian. Chat ID
 * berganti setiap grup Diskominfo dibuat ulang atau bot dikeluarkan lalu
 * diundang kembali, dan token berganti setiap bot diputar. Keduanya terjadi
 * tepat pada saat alert sedang dibutuhkan, yaitu saat paling buruk untuk
 * menunggu deploy.
 *
 * Gagalnya diam pula. Aturan alert tetap tersimpan rapi dan tetap terpicu
 * benar, hanya tidak ada satu pun pesan yang sampai. Itu jenis kerusakan yang
 * baru ketahuan saat seseorang bertanya kenapa tidak ada peringatan sama
 * sekali minggu ini.
 *
 * Syarat dokumen 06 tetap terpenuhi tanpa deploy: modelnya memakai activity
 * log, jadi siapa yang mengganti chat ID dan menjadi apa tetap tercatat.
 *
 * `.env` tidak ditinggalkan sebagai cadangan, dan itu mengikuti keputusan yang
 * sama pada migrasi kunci Gemini. Dua sumber untuk satu nilai berarti suatu
 * hari keduanya berbeda, dan yang kalah tetap terbaca sebagai nilai yang
 * berlaku oleh siapa pun yang membukanya. Setelah migrasi ini `TELEGRAM_BOT_TOKEN`
 * dan `TELEGRAM_CHAT_ID` tidak dibaca kode mana pun dan boleh dihapus dari
 * berkas `.env` yang sudah berjalan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_alert', function (Blueprint $table) {
            $table->id();

            // Terenkripsi lewat cast model, sama seperti kunci Gemini. Token
            // bot adalah kredensial penuh: siapa pun yang memilikinya bisa
            // mengirim pesan atas nama bot ke grup mana pun yang dimasukinya.
            // Dump database beredar lebih luas daripada `.env`.
            $table->text('telegram_token')->nullable();

            // Bukan integer. Chat ID grup berupa angka negatif panjang seperti
            // -1001234567890, dan Telegram juga menerima bentuk @namakanal
            // untuk kanal publik.
            $table->string('telegram_chat_id', 40)->nullable();

            $table->timestampsTz();
        });

        // Satu baris, ditegakkan database. Sama seperti `pengaturan_ai`: baris
        // kedua tidak akan pernah dibaca kode mana pun, jadi keberadaannya
        // hanya membuat admin menyunting pengaturan yang tidak dipakai.
        DB::statement('ALTER TABLE pengaturan_alert ADD CONSTRAINT chk_pengaturan_alert_baris_tunggal CHECK (id = 1)');

        $this->isiDariEnv();
    }

    /**
     * Nilai `.env` yang sedang berlaku disalin sekali, lalu tidak dibaca lagi.
     *
     * Ini satu-satunya titik `.env` dan database bertemu, dan pertemuannya
     * berhenti begitu migrasi ini selesai. Tanpa penyalinan ini, instalasi yang
     * sudah berjalan kehilangan seluruh alert-nya pada detik migrasi dijalankan
     * dan tidak ada satu pun pesan galat yang menyebutkan sebabnya. Alert yang
     * berhenti tanpa jejak persis kerusakan yang tabel ini ada untuk dicegah.
     *
     * Tokennya dienkripsi di sini, bukan diserahkan ke cast model. Migrasi
     * menulis lewat query builder yang tidak melewati Eloquent, jadi nilai
     * mentah akan tersimpan apa adanya lalu gagal didekripsi saat model
     * membacanya. `Crypt::encryptString` adalah persis yang dipakai cast
     * `encrypted`, jadi keduanya sepakat.
     */
    private function isiDariEnv(): void
    {
        $token = (string) env('TELEGRAM_BOT_TOKEN', '');
        $chatId = (string) env('TELEGRAM_CHAT_ID', '');

        DB::table('pengaturan_alert')->insert([
            'id' => 1,
            'telegram_token' => $token === '' ? null : Crypt::encryptString($token),
            'telegram_chat_id' => $chatId === '' ? null : $chatId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan_alert');
    }
};
