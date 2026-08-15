<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Menjadwalkan ulang kegagalan AI dan menutup dua prioritas legacy. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('antrean_gemini', function (Blueprint $tabel) {
            // Berbeda dari dijadwalkan_at yang menandakan job sudah hidup di
            // Redis. Kolom ini adalah waktu backoff sebelum job boleh dibuat.
            $tabel->timestampTz('coba_lagi_at')->nullable();
            $tabel->index(['status', 'coba_lagi_at']);
        });

        // Prioritas 2 dan 3 berasal dari migrasi pipeline lama. Artikelnya sudah
        // memiliki keputusan dan tidak perlu lagi dinilai otomatis.
        DB::table('antrean_gemini')
            ->whereIn('prioritas', [2, 3])
            ->where('status', '<>', 'selesai')
            ->update([
                'status' => 'selesai',
                'galat' => null,
                'dijadwalkan_at' => null,
                'coba_lagi_at' => null,
                'selesai_at' => now(),
                'updated_at' => now(),
            ]);

        // Ada instalasi yang artikelnya sudah selesai lewat klasifikasi manual,
        // tetapi riwayat antreannya masih berstatus gagal. Baris seperti ini
        // ditutup, bukan dijadwalkan ulang dan ditampilkan sebagai kendala.
        DB::table('antrean_gemini')
            ->where('prioritas', 1)
            ->where('status', '<>', 'selesai')
            ->whereIn('artikel_id', DB::table('artikel')
                ->whereIn('status_proses', ['selesai', 'tidak_relevan', 'perlu_review'])
                ->select('id'))
            ->update([
                'status' => 'selesai',
                'galat' => null,
                'dijadwalkan_at' => null,
                'coba_lagi_at' => null,
                'updated_at' => now(),
            ]);

        // Empat baris yang telanjur berhenti setelah tiga kali gagal (dan baris
        // serupa pada instalasi lain) langsung masuk siklus retry pada tarikan
        // scheduler berikutnya. Jumlah percobaan dipertahankan agar backoff
        // selanjutnya tetap makin longgar bila penyebabnya belum pulih.
        DB::table('antrean_gemini')
            ->where('prioritas', 1)
            ->where('status', 'gagal')
            ->update([
                'dijadwalkan_at' => null,
                'coba_lagi_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('antrean_gemini', function (Blueprint $tabel) {
            $tabel->dropIndex(['status', 'coba_lagi_at']);
            $tabel->dropColumn('coba_lagi_at');
        });
    }
};
