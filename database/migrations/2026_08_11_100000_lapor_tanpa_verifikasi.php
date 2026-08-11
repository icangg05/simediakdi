<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membuang antrean verifikasi. Berita yang ditambahkan media langsung sah.
 *
 * Keputusan produknya: media partner tahu berita mana yang mereka terbitkan
 * sendiri, dan domain URL sudah dipastikan milik mereka saat pengiriman. Tidak
 * ada yang tersisa untuk dinilai admin selain menekan tombol Terima pada
 * hampir semua baris, dan antrean yang selalu dijawab "ya" hanya menunda
 * berita masuk arsip.
 *
 * Dengan verifikasi hilang, tabel `pemuatan` kehilangan seluruh alasannya. Yang
 * disimpannya di luar status verifikasi hanya url, judul, dan siapa yang
 * mengirim, dan dua yang pertama sudah ada di tabel artikel. Yang ketiga
 * pindah ke sana sebagai satu kolom.
 *
 * `dilaporkan_oleh` bukan sekadar catatan. Ia yang membedakan berita temuan
 * crawler dari berita kiriman media, dan pembedaan itu dipakai kartu F-54 di
 * dashboard admin untuk memperingatkan bias: media memilih sendiri berita mana
 * yang dikirim, dan berita kritis jarang termasuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artikel', function (Blueprint $table) {
            $table->foreignId('dilaporkan_oleh')->nullable()->constrained('users')->nullOnDelete();
        });

        // Laporan yang sudah terverifikasi sebelum migration ini sudah tertaut
        // ke artikelnya. Yang pindah hanya siapa pengirimnya.
        if (Schema::hasTable('pemuatan')) {
            DB::statement("UPDATE artikel SET dilaporkan_oleh = p.dilaporkan_oleh
                FROM pemuatan p
                WHERE p.artikel_id = artikel.id
                  AND p.dilaporkan_oleh IS NOT NULL
                  AND p.status_verifikasi = 'terverifikasi'");
        }

        Schema::dropIfExists('pemuatan');
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Penghapusan antrean verifikasi tidak bisa dibatalkan lewat migration. '
            .'Skema tabel pemuatan harus dibangun ulang dari riwayat git dan cadangan database.'
        );
    }
};
