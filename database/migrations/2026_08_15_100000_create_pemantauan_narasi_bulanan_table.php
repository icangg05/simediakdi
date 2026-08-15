<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keadaan proses Gemini untuk laporan satu bulan kalender.
 *
 * Narasi dan keadaan proses sengaja dipisah. Narasi adalah hasil terakhir yang
 * berhasil dan tetap boleh dibaca ketika percobaan berikutnya gagal, sedangkan
 * tabel ini menjawab pertanyaan operasional admin: prosesnya sedang berjalan,
 * sudah final, belum punya bahan, atau berhenti karena galat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pemantauan_narasi_bulanan', function (Blueprint $table) {
            $table->id();
            // Selalu tanggal satu dalam WITA. Satu bulan hanya punya satu
            // keadaan proses, walaupun pemeriksaannya terjadi berkali-kali.
            $table->date('bulan')->unique();
            $table->string('status', 20)->default('menunggu');
            // Bulan yang dikunci tidak pernah dikirim lagi ke Gemini.
            $table->boolean('dikunci')->default(false);
            // Menghitung pemeriksaan penjadwal, bukan jumlah permintaan Gemini.
            // Pemeriksaan tanpa perubahan data berhenti sebelum memanggil AI.
            $table->unsignedInteger('pemeriksaan')->default(0);
            $table->foreignId('narasi_eksekutif_id')
                ->nullable()
                ->constrained('narasi_eksekutif')
                ->nullOnDelete();
            $table->text('galat')->nullable();
            $table->timestampTz('mulai_at')->nullable();
            $table->timestampTz('selesai_at')->nullable();
            $table->timestampTz('gagal_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'dikunci']);
        });

        DB::statement("ALTER TABLE pemantauan_narasi_bulanan
            ADD CONSTRAINT chk_pemantauan_narasi_bulanan_status
            CHECK (status IN ('menunggu','berjalan','selesai','gagal','tanpa_data'))");

        // Instalasi yang sudah memiliki narasi bulanan langsung mendapat jejak
        // pemantauan. Bulan lampau dianggap final supaya hasil lama tidak tiba-
        // tiba berubah hanya karena fitur pemantauan baru dipasang.
        $awalBulanIni = CarbonImmutable::now('Asia/Makassar')->startOfMonth();

        DB::table('narasi_eksekutif')
            ->where('periode', '30d')
            ->orderBy('dari')
            ->each(function (object $narasi) use ($awalBulanIni): void {
                $bulan = CarbonImmutable::parse((string) $narasi->dari, 'Asia/Makassar')->startOfMonth();

                // Sebelum laporan bulanan tersedia, preset 30d merupakan 30
                // hari bergulir. Hanya hasil yang tepat mulai tanggal satu dan
                // berakhir pada akhir bulan yang boleh dibackfill sebagai satu
                // laporan kalender.
                if ((string) $narasi->dari !== $bulan->toDateString()
                    || (string) $narasi->sampai !== $bulan->endOfMonth()->toDateString()) {
                    return;
                }

                DB::table('pemantauan_narasi_bulanan')->updateOrInsert(
                    ['bulan' => $bulan->toDateString()],
                    [
                        'status' => 'selesai',
                        'dikunci' => $bulan->lt($awalBulanIni),
                        'pemeriksaan' => 1,
                        'narasi_eksekutif_id' => $narasi->id,
                        'galat' => null,
                        'selesai_at' => $narasi->dibuat_at,
                        'created_at' => $narasi->created_at,
                        'updated_at' => $narasi->updated_at,
                    ],
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('pemantauan_narasi_bulanan');
    }
};
