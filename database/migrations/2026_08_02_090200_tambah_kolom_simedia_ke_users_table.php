<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 120)->change();
            $table->string('email', 180)->change();

            // Sengaja tanpa default: user yang dibuat tanpa peran harus gagal
            // keras, bukan diam-diam menjadi superadmin.
            $table->string('peran', 20)->after('password');
            $table->foreignId('media_id')->nullable()->after('peran')->constrained('media')->nullOnDelete();
            $table->string('jabatan', 120)->nullable()->after('media_id');
            $table->string('telepon', 30)->nullable()->after('jabatan');
            $table->boolean('aktif')->default(true)->after('telepon');
            $table->timestampTz('login_terakhir_at')->nullable()->after('aktif');
            $table->string('ip_login_terakhir', 45)->nullable()->after('login_terakhir_at');
            $table->softDeletes();

            $table->index(['peran', 'aktif']);
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_peran
            CHECK (peran IN ('superadmin','walikota','media'))");

        // Tanpa constraint ini, satu bug di form pembuatan user bisa membuat
        // superadmin yang punya media_id dan global scope jadi tak terduga.
        DB::statement("ALTER TABLE users ADD CONSTRAINT chk_media_id_sesuai_peran CHECK (
            (peran = 'media' AND media_id IS NOT NULL) OR
            (peran <> 'media' AND media_id IS NULL)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_media_id_sesuai_peran');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_peran');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['media_id']);
            $table->dropIndex(['peran', 'aktif']);
            $table->dropColumn([
                'peran', 'media_id', 'jabatan', 'telepon', 'aktif',
                'login_terakhir_at', 'ip_login_terakhir', 'deleted_at',
            ]);
        });
    }
};
