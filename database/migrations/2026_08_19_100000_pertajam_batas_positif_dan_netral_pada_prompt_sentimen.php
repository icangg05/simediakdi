<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mempertajam batas antara positif dan netral pada prompt sentimen.
 *
 * Lahir dari satu peristiwa yang diberitakan empat media: kebijakan Wali Kota
 * yang mewajibkan ASN menyetor dua kilogram sampah anorganik per bulan. Dua
 * media dinilai positif dengan reason_code capaian_program, dua lainnya dinilai
 * netral dengan agenda_kegiatan dan kebijakan_pemerintah. Peristiwanya satu,
 * labelnya dua, dan bedanya hanya pada ada tidaknya kalimat wartawan yang
 * menilai hasil kerja Pemkot.
 *
 * Sebabnya ada di daftar POSITIF: ia menyebut keberhasilan, manfaat, dukungan,
 * perbaikan, prestasi, penghargaan, dan respons cepat, tetapi tidak menyebut
 * inisiatif kebijakan yang baru diluncurkan. Kebijakan baru yang membawa
 * manfaat bagi warga jatuh ke celah antara kedua daftar, dan model memilih
 * netral karena NETRAL menyebut "agenda kegiatan" tanpa membedakan agenda dari
 * kebijakan.
 *
 * Perbaikannya menutup celah itu dari dua sisi sekaligus: POSITIF menyebut
 * inisiatif dan kebijakan secara eksplisit, NETRAL dipersempit ke pemberitaan
 * yang benar-benar hanya memindahkan informasi. Nomor versi ikut naik supaya
 * hasil dari prompt lama dan prompt baru tidak tercampur saat dibandingkan.
 *
 * Yang tidak diubah: aturan dasar nomor 2, yang melarang model menilai bagus
 * tidaknya sebuah kebijakan menurut pendapatnya sendiri. Yang dinilai tetap
 * bagaimana teks menggambarkan Pemkot, bukan mutu kebijakannya.
 */
return new class extends Migration
{
    private const POSITIF_LAMA = 'Sisi menguntungkan yang dominan: keberhasilan, manfaat bagi warga, dukungan,
perbaikan, prestasi, penghargaan, atau respons cepat yang digambarkan baik.';

    private const POSITIF_BARU = 'Sisi menguntungkan yang dominan: keberhasilan, manfaat bagi warga, dukungan,
perbaikan, prestasi, penghargaan, atau respons cepat yang digambarkan baik.

Termasuk di sini kebijakan, program, dan inisiatif baru yang dijalankan pihak
konteks untuk kepentingan warga, sekalipun hasilnya belum terlihat karena
programnya baru dimulai. Yang dinilai adalah tindakannya, yaitu pihak konteks
mengerjakan sesuatu yang ditujukan untuk memperbaiki keadaan. Contohnya
kewajiban baru bagi aparatur untuk mendukung program lingkungan, penambahan
layanan, bantuan yang mulai disalurkan, atau pembentukan tim untuk menangani
satu persoalan.

Ini bukan penilaian atas bagus tidaknya kebijakan itu. Bila artikel yang sama
juga memuat keberatan warga, keluhan, atau kritik atas kebijakan tersebut,
timbang keduanya seperti biasa dan jangan otomatis positif.';

    private const NETRAL_LAMA = 'Informasi faktual atau administratif tanpa penilaian yang dominan ke salah
satu sisi. Pengumuman jadwal, agenda kegiatan, data anggaran, dan kutipan
prosedural biasanya masuk di sini.';

    private const NETRAL_BARU = 'Informasi faktual atau administratif yang hanya memindahkan keterangan, tanpa
tindakan pihak konteks yang menguntungkan maupun memberatkan. Pengumuman
jadwal, undangan, agenda rapat dan kunjungan, angka anggaran yang disebut apa
adanya, kutipan prosedural, serta berita yang hanya menyebut pihak konteks
sebagai pelengkap keterangan.

Kehadiran pejabat pada sebuah acara, sambutan seremonial, dan foto kegiatan
tetap netral selama tidak ada kebijakan, program, atau hasil kerja yang
diberitakan.';

    public function up(): void
    {
        $this->ganti(self::POSITIF_LAMA, self::POSITIF_BARU, 'sentiment-v3');
    }

    public function down(): void
    {
        $this->ganti(self::POSITIF_BARU, self::POSITIF_LAMA, 'sentiment-v2');
    }

    /**
     * Mengganti kedua blok sekaligus.
     *
     * Prompt boleh disunting admin dari halaman Pengaturan AI, jadi teks lama
     * belum tentu masih ada persis seperti yang ditulis migration sebelumnya.
     * Yang tidak cocok dilewati tanpa membuat migration gagal, karena
     * memaksakan teks baku ke prompt yang sudah disunting tangan akan membuang
     * suntingan itu diam-diam.
     */
    private function ganti(string $positifDari, string $positifKe, string $versi): void
    {
        $netralDari = $versi === 'sentiment-v3' ? self::NETRAL_LAMA : self::NETRAL_BARU;
        $netralKe = $versi === 'sentiment-v3' ? self::NETRAL_BARU : self::NETRAL_LAMA;

        DB::table('pengaturan_ai')
            ->select(['id', 'prompt_sentimen'])
            ->orderBy('id')
            ->each(function (object $pengaturan) use ($positifDari, $positifKe, $netralDari, $netralKe, $versi): void {
                $prompt = $pengaturan->prompt_sentimen;

                if (! str_contains($prompt, $positifDari) || ! str_contains($prompt, $netralDari)) {
                    return;
                }

                DB::table('pengaturan_ai')
                    ->where('id', $pengaturan->id)
                    ->update([
                        'prompt_sentimen' => str_replace(
                            [$positifDari, $netralDari],
                            [$positifKe, $netralKe],
                            $prompt,
                        ),
                        'versi_prompt_sentimen' => $versi,
                    ]);
            });
    }
};
