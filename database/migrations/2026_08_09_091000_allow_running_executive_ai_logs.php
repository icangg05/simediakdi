<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE executive_ai_logs DROP CONSTRAINT IF EXISTS chk_executive_ai_log_status');
        DB::statement("ALTER TABLE executive_ai_logs ADD CONSTRAINT chk_executive_ai_log_status CHECK (status IN ('berjalan','berhasil','gagal'))");
    }

    public function down(): void
    {
        DB::table('executive_ai_logs')->where('status', 'berjalan')->update([
            'status' => 'gagal',
            'error' => 'Proses dihentikan saat migration dibatalkan.',
        ]);

        DB::statement('ALTER TABLE executive_ai_logs DROP CONSTRAINT IF EXISTS chk_executive_ai_log_status');
        DB::statement("ALTER TABLE executive_ai_logs ADD CONSTRAINT chk_executive_ai_log_status CHECK (status IN ('berhasil','gagal'))");
    }
};
