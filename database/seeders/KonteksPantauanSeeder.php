<?php

namespace Database\Seeders;

use App\Models\KonteksPantauan;
use Illuminate\Database\Seeder;

/**
 * Tiga konteks awal, jawaban Diskominfo nomor 5 (dokumen 01 bagian 9).
 *
 * `nama` dikirim apa adanya ke model IndoBERT sebagai input konteks.
 * `kata_kunci` adalah penyaring murah sebelum model relevansi dipanggil —
 * menambah konteks menaikkan biaya inferensi secara linear.
 */
class KonteksPantauanSeeder extends Seeder
{
    public function run(): void
    {
        $daftar = [
            [
                'nama' => 'Pemerintah Kota Kendari',
                'slug' => 'pemerintah-kota-kendari',
                'deskripsi' => 'Kebijakan, program, layanan, dan aparatur Pemerintah Kota Kendari.',
                'kata_kunci' => [
                    'pemkot kendari', 'pemerintah kota kendari', 'kota kendari',
                    'dinas', 'opd', 'apbd kendari', 'sekda kendari', 'diskominfo kendari',
                ],
                'utama' => true,
                'urutan' => 1,
            ],
            [
                'nama' => 'Wali Kota Kendari',
                'slug' => 'wali-kota-kendari',
                'deskripsi' => 'Wali Kota dan Wakil Wali Kota Kendari sebagai pejabat publik.',
                'kata_kunci' => [
                    'wali kota kendari', 'walikota kendari', 'wakil wali kota kendari',
                    'wawali kendari', 'orang nomor satu kendari',
                ],
                'utama' => false,
                'urutan' => 2,
            ],
            [
                'nama' => 'Pelayanan publik dan infrastruktur Kota Kendari',
                'slug' => 'pelayanan-publik-infrastruktur-kendari',
                'deskripsi' => 'Jalan, drainase, sampah, air bersih, pasar, dan layanan administrasi kependudukan.',
                'kata_kunci' => [
                    'jalan rusak', 'drainase', 'banjir', 'sampah', 'tpa', 'air bersih',
                    'pdam', 'pasar', 'dukcapil', 'adminduk', 'pelayanan publik', 'trotoar',
                ],
                'utama' => false,
                'urutan' => 3,
            ],
        ];

        foreach ($daftar as $konteks) {
            KonteksPantauan::updateOrCreate(['slug' => $konteks['slug']], $konteks);
        }
    }
}
