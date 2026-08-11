<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // Kapan pencarian feed otomatis terakhir kali selesai dijalankan.
            //
            // Dipakai membedakan dua keadaan yang sama-sama berarti media belum
            // punya sumber feed, tetapi menuntut tindakan yang berbeda: null
            // berarti pencariannya masih diantrekan dan admin tidak perlu
            // berbuat apa pun, terisi berarti pencariannya sudah selesai dan
            // tidak menemukan apa-apa, jadi alamatnya harus diisi tangan.
            $table->timestampTz('feed_dicari_at')->nullable()->after('aktif');
        });

        // Media yang sudah punya sumber feed dianggap sudah selesai dicari.
        // Tanpa ini seluruh baris lama akan tampil sebagai "sedang dicari"
        // padahal tidak ada satu pun pekerjaan yang mengantre untuk mereka.
        DB::statement('UPDATE media SET feed_dicari_at = now()
            WHERE EXISTS (SELECT 1 FROM sumber_feed WHERE sumber_feed.media_id = media.id)');
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('feed_dicari_at');
        });
    }
};
