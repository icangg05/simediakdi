<?php

namespace Database\Seeders;

use App\Models\KonteksPantauan;
use Illuminate\Database\Seeder;

/**
 * Satu konteks: Pemerintah Kota Kendari (dokumen 01 bagian 9, revisi 1.4).
 *
 * Konteks Wali Kota dan Pelayanan publik dihapus pada 4 Agustus 2026. Keduanya
 * sempat dinonaktifkan lebih dulu, tapi baris nonaktif di tabel tetap terbaca
 * admin dan menimbulkan pertanyaan yang tidak perlu. 221 label gold set-nya
 * diarsipkan ke `storage/app/private/arsip-gold-set-konteks-lama.json` sebelum
 * dihapus, karena label itu hasil kerja manusia dan tidak bisa dibuat ulang
 * tanpa membacanya lagi satu per satu.
 *
 * Dua kolom deskripsi, dan bedanya penting:
 *
 * - `deskripsi` dibaca manusia di halaman admin, tidak pernah dikirim ke mana pun.
 * - `deskripsi_model` adalah kalimat konteks yang dipasangkan ke artikel pada
 *   tokenizer model relevansi. Pendek dan persis, bukan paragraf aturan.
 *   Mengubahnya mewajibkan model dievaluasi ulang.
 *
 * `nama` dikirim apa adanya ke model sentimen sebagai input konteks.
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
                // Kalimat konteks yang dipasangkan ke artikel pada tokenizer,
                // dan ia harus pendek.
                //
                // Sebelumnya berisi paragraf aturan inklusi dan eksklusi, dan
                // itu tepat pada revisi 1.5 ketika teksnya di-embed sebagai
                // pembanding kemiripan makna. Sejak relevansi kembali menjadi
                // classifier, paragraf itu justru merugikan: ia memakan 137
                // dari 256 token, isinya sama persis di setiap sampel sehingga
                // tidak membedakan apa pun, dan artikel yang seharusnya dinilai
                // tinggal kebagian 116 token.
                //
                // Aturan inklusi dan eksklusinya tetap berguna, tempatnya di
                // panduan pelabelan dokumen 09 dan di `versi_konteks_relevansi`,
                // bukan di teks yang dikirim ke model. Dokumen 10 bagian 20.
                'deskripsi_model' => 'Pemerintah Kota Kendari',
                'kata_kunci' => [
                    'pemkot kendari', 'pemerintah kota kendari', 'kota kendari',
                    'dinas', 'opd', 'apbd kendari', 'sekda kendari', 'diskominfo kendari',
                ],
                'utama' => true,
                'urutan' => 1,
            ],
        ];

        foreach ($daftar as $konteks) {
            KonteksPantauan::updateOrCreate(['slug' => $konteks['slug']], $konteks);
        }
    }
}
