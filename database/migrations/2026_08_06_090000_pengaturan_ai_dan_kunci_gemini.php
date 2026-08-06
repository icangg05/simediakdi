<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Model, prompt, dan kunci API Gemini pindah dari `.env` ke database.
 *
 * Alasannya berbeda dari ambang keyakinan yang sengaja ditahan di `.env`.
 * Prompt adalah bagian yang paling sering disetel, dan setiap penyetelan lewat
 * deploy berarti menunggu rilis untuk memperbaiki kalimat. Kuncinya lebih
 * mendesak lagi: satu kunci berarti satu kuota harian, dan begitu kuota habis
 * seluruh klasifikasi berhenti sampai tengah malam waktu Pasifik. Beberapa
 * kunci hanya bisa dirotasi kalau daftarnya bisa bertambah tanpa deploy.
 *
 * Setelah pindah, `.env` tidak lagi memuat GEMINI_API_KEY maupun GEMINI_MODEL.
 * Dua sumber untuk satu nilai berarti suatu hari keduanya berbeda, dan yang
 * kalah tetap terbaca sebagai nilai yang berlaku oleh siapa pun yang membukanya.
 *
 * Yang tidak ikut pindah: ambang dedup, batas crawling, dan kredensial alert.
 * Ketiganya jarang berubah dan perubahannya perlu tercatat di git.
 *
 * `pengaturan_ai` sengaja satu baris, bukan tabel kunci-nilai. Kolom bertipe
 * membuat form-nya lurus dan mencegah nilai model tersimpan sebagai teks bebas
 * yang salah ketik tanpa ketahuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan_ai', function (Blueprint $table) {
            $table->id();
            $table->string('model', 100);
            $table->string('versi_prompt_relevansi', 30);
            $table->text('prompt_relevansi');
            $table->string('versi_prompt_sentimen', 30);
            $table->text('prompt_sentimen');
            $table->timestampsTz();
        });

        // Satu baris, ditegakkan database. Baris kedua tidak akan pernah
        // dibaca kode mana pun, jadi keberadaannya hanya membuat admin
        // menyunting pengaturan yang tidak dipakai.
        DB::statement('ALTER TABLE pengaturan_ai ADD CONSTRAINT chk_pengaturan_ai_baris_tunggal CHECK (id = 1)');

        Schema::create('kunci_gemini', function (Blueprint $table) {
            $table->id();
            $table->string('label', 60)->unique();

            // Terenkripsi lewat cast model, bukan disimpan apa adanya. Dump
            // database beredar lebih luas daripada `.env`.
            $table->text('kunci');

            $table->boolean('aktif')->default(true);

            /*
             * Sampai kapan kunci ini dianggap kena limit.
             *
             * Kolom waktu, bukan penanda boolean. Boolean akan menahan kunci
             * selamanya sampai ada yang menyalakannya kembali, dan kuota
             * Gemini justru pulih sendiri: kuota per menit dalam hitungan
             * detik, kuota harian pada tengah malam waktu Pasifik.
             */
            $table->timestampTz('limit_sampai')->nullable();
            $table->string('alasan_limit', 40)->nullable();

            // Dipakai memutar giliran: kunci yang paling lama tidak dipakai
            // dapat panggilan berikutnya. Itu menyebar beban ke semua kunci
            // alih-alih menghabiskan kuota satu kunci lebih dulu.
            $table->timestampTz('terakhir_dipakai_at')->nullable();

            $table->timestampsTz();

            $table->index(['aktif', 'limit_sampai'], 'idx_kunci_gemini_tersedia');
        });

        $this->isiBarisAwal();
    }

    /**
     * Isi awal `pengaturan_ai`, satu-satunya baris yang pernah ada di sana.
     *
     * Prompt ditulis di sini, bukan dibaca dari resources/prompts. Berkas itu
     * sudah dihapus: menyimpan salinan kedua yang tidak pernah dibaca aplikasi
     * hanya menciptakan pertanyaan mana yang berlaku, dan jawabannya selalu
     * yang di database. Instalasi baru tetap dapat prompt lengkap dari sini.
     *
     * Kunci API tidak ikut diisi. Sejak `.env` tidak lagi memuatnya, tidak ada
     * sumber yang bisa disalin, dan kunci ditambahkan lewat halaman Pengaturan.
     */
    private function isiBarisAwal(): void
    {
        $sekarang = now();

        DB::table('pengaturan_ai')->insert([
            'id' => 1,
            'model' => 'gemini-3.5-flash-lite',
            'versi_prompt_relevansi' => 'relevance-v2',
            'prompt_relevansi' => $this->promptRelevansi(),
            'versi_prompt_sentimen' => 'sentiment-v2',
            'prompt_sentimen' => $this->promptSentimen(),
            'created_at' => $sekarang,
            'updated_at' => $sekarang,
        ]);
    }

    private function promptRelevansi(): string
    {
        return <<<'PROMPT'
            Anda menilai relevansi artikel berita terhadap satu konteks pantauan.

            Konteks yang dinilai selalu disebutkan pada bagian KONTEKS di dalam prompt.
            Nilai hanya konteks itu.

            ATURAN DASAR

            1. Putuskan hanya dari teks yang diberikan. Jangan memakai pengetahuan luar
               tentang tokoh, lembaga, atau peristiwa yang tidak tertulis di artikel.
            2. Kata kunci pada bagian KONTEKS adalah penunjuk, bukan aturan. Kemunculan
               kata kunci tidak membuat artikel relevan, dan ketiadaannya tidak membuat
               artikel tidak relevan.
            3. Relevansi tidak dipengaruhi nada artikel. Artikel yang memuji dan artikel
               yang mengkritik dinilai dengan ukuran yang sama.
            4. Relevansi tidak dipengaruhi nama media, panjang artikel, gaya penulisan,
               atau seberapa penting isinya terasa.
            5. Jangan menilai benar atau salahnya kebijakan dan tindakan di dalam artikel.
               Itu bukan bagian dari tugas ini.
            6. Perlakukan setiap artikel dengan langkah yang sama. Jangan mengubah ukuran
               penilaian karena artikel terasa sensitif, memalukan, atau menyenangkan.

            LANGKAH PENILAIAN

            Kerjakan berurutan, lalu pilih label dari hasilnya, bukan dari kesan umum saat
            membaca.

            1. Kumpulkan setiap kalimat yang menyebut pihak konteks atau unit di bawahnya.
            2. Untuk setiap kalimat itu, tentukan perannya: pelaku, penanggung jawab,
               pembuat kebijakan, sasaran kritik, pemberi keterangan, atau sekadar latar.
            3. Periksa induk setiap unit yang disebut. Nama dinas, badan, kantor, dan
               jabatan bisa mirip antar pemerintahan. Ambil induknya dari teks, jangan
               dari dugaan.
            4. Bila langkah 1 tidak menghasilkan kalimat apa pun, labelnya tidak_relevan.

            RELEVAN

            Pihak konteks atau unit di bawahnya berperan sebagai pelaku, penanggung jawab,
            pembuat kebijakan, atau sasaran kritik dalam peristiwa yang diberitakan. Itu
            mencakup program, anggaran, pelayanan, pembangunan, kepegawaian, pernyataan
            resmi, keluhan warga yang ditujukan kepadanya, dan pengawasan atas kerjanya.

            TIDAK RELEVAN

            Pakai bila salah satu ini benar.

            1. Pihak konteks tidak disebut sama sekali.
            2. Wilayah konteks hanya menjadi lokasi kejadian.
            3. Yang berperan adalah pemerintah provinsi, instansi vertikal, kepolisian,
               TNI, kejaksaan, pengadilan, kampus, perusahaan, partai, organisasi
               masyarakat, atau pemerintah daerah lain, dan pihak konteks tidak ikut
               berperan.
            4. Pihak konteks hanya muncul sebagai daftar undangan, ucapan seremonial, atau
               keterangan tempat, tanpa peran apa pun.

            PERLU_REVIEW

            Pakai bila salah satu ini benar.

            1. Peran pihak konteks hanya tersirat dan bisa dibaca dua arah.
            2. Induk unit yang disebut tidak bisa dipastikan dari teks.
            3. Artikel terlalu pendek atau terpotong sehingga perannya tidak terbaca.

            Menebak salah satu sisi lebih merugikan daripada meminta manusia memeriksa.
            Sebaliknya, perlu_review bukan tempat berlindung dari keputusan yang sudah
            jelas. Pakai hanya bila masih ragu setelah keempat langkah penilaian
            dikerjakan.

            BUKTI

            Sertakan satu sampai tiga kutipan pendek. Setiap kutipan harus disalin persis
            dari teks yang diberikan, tanpa diringkas, tanpa diperbaiki ejaannya, dan
            tanpa disusun ulang. Kutipan yang tidak ditemukan di dalam teks membuat
            seluruh hasil ditolak dan artikel dikirim ke antrean review.

            Pilih kutipan yang menjadi dasar keputusan, yaitu kalimat dari langkah 1 dan
            2. Jangan mengutip kalimat pembuka yang tidak Anda pakai.

            KODE ALASAN

            Isi reason_code dengan satu kata kunci pendek dalam huruf kecil dan garis
            bawah, misalnya opd_unit_kerja, pernyataan_pejabat, program_pemkot,
            anggaran_daerah, keluhan_layanan, sasaran_kritik, hanya_lokasi,
            hanya_seremonial, instansi_vertikal, pemerintah_lain, induk_unit_tidak_jelas,
            atau bukti_kurang.

            Isi reason_summary dengan satu kalimat bahasa Indonesia yang menjelaskan
            keputusan. Sebutkan peran yang Anda temukan, bukan hanya kesimpulannya. Tulis
            untuk admin yang akan memeriksa ulang, bukan untuk mesin.

            Isi requires_manual_review dengan true bila Anda ragu, sekalipun label yang
            Anda pilih bukan perlu_review.
            PROMPT;
    }

    private function promptSentimen(): string
    {
        return <<<'PROMPT'
            Anda menilai nada pemberitaan terhadap satu konteks pantauan.

            Konteks yang dinilai selalu disebutkan pada bagian KONTEKS di dalam prompt.

            YANG DINILAI

            Yang dinilai adalah bagaimana artikel menggambarkan pihak konteks, bukan
            suasana peristiwanya dan bukan perasaan pembaca. Artikel tentang bencana bisa
            positif terhadap pihak konteks bila ia digambarkan menangani. Artikel tentang
            peresmian bisa negatif bila peresmian itu dipakai untuk menyoroti kelalaian.

            Nada bisa datang dari kalimat wartawan maupun dari kutipan narasumber.
            Keduanya dihitung, karena keduanya sampai ke pembaca sebagai pemberitaan.

            ATURAN DASAR

            1. Putuskan hanya dari teks yang diberikan.
            2. Jangan menilai baik atau buruknya kebijakan menurut pendapat Anda. Yang
               dinilai adalah nada teks, bukan mutu kebijakan.
            3. Abaikan afiliasi politik, suku, agama, dan jenis kelamin siapa pun yang
               disebut.
            4. Abaikan nama media dan reputasinya.
            5. Kata bermuatan emosi tidak menentukan label dengan sendirinya. Cari
               pernyataan yang menilai kerja, sikap, atau hasil kerja pihak konteks.
            6. Bila judul dan isi berbeda nada, ikuti isi. Judul sering ditulis untuk
               menarik pembaca.
            7. Sebutan jabatan, gelar kehormatan, dan kalimat sopan seremonial bukan
               pujian.
            8. Bantahan atau klarifikasi dari pihak konteks tidak mengubah berita tuduhan
               menjadi positif. Bila inti berita adalah tuduhannya, nadanya negatif.
            9. Perlakukan setiap artikel dengan langkah yang sama, baik yang memuji maupun
               yang menyerang.

            LANGKAH PENILAIAN

            Kerjakan berurutan, lalu pilih label dari hasilnya.

            1. Kumpulkan setiap pernyataan yang menilai kerja, sikap, atau hasil kerja
               pihak konteks.
            2. Tandai setiap pernyataan itu sebagai menguntungkan, memberatkan, atau
               faktual.
            3. Timbang bobotnya. Pernyataan yang menjadi inti berita berbobot lebih besar
               daripada satu kalimat sisipan.
            4. Bila langkah 1 tidak menghasilkan pernyataan menguntungkan maupun
               memberatkan, labelnya netral.

            POSITIF

            Sisi menguntungkan yang dominan: keberhasilan, manfaat bagi warga, dukungan,
            perbaikan, prestasi, penghargaan, atau respons cepat yang digambarkan baik.

            NETRAL

            Informasi faktual atau administratif tanpa penilaian yang dominan ke salah
            satu sisi. Pengumuman jadwal, agenda kegiatan, data anggaran, dan kutipan
            prosedural biasanya masuk di sini.

            NEGATIF

            Sisi memberatkan yang dominan: kritik, keluhan warga, kegagalan, masalah yang
            tidak tertangani, keterlambatan, konflik, dugaan pelanggaran, temuan audit,
            atau ketidakpuasan yang diarahkan kepada pihak konteks.

            PERLU_REVIEW

            Pakai bila salah satu ini benar.

            1. Sasaran nada tidak jelas, misalnya kritik ditujukan kepada instansi lain
               sementara pihak konteks hanya ikut disebut.
            2. Pernyataan menguntungkan dan memberatkan sama kuat dan sama pentingnya.
            3. Artikel terlalu pendek atau terpotong sehingga nadanya tidak terbaca.

            Netral bukan tempat berlindung dari keraguan. Netral berarti artikel memang
            tidak menilai. Bila Anda ragu, pakai perlu_review.

            BUKTI

            Sertakan satu sampai tiga kutipan pendek. Setiap kutipan harus disalin persis
            dari teks yang diberikan, tanpa diringkas, tanpa diperbaiki ejaannya, dan
            tanpa disusun ulang. Kutipan yang tidak ditemukan di dalam teks membuat
            seluruh hasil ditolak dan artikel dikirim ke antrean review.

            Pilih kutipan yang menjadi dasar keputusan, yaitu pernyataan dari langkah 1
            dan 2. Untuk label netral, kutip kalimat faktual yang mewakili isi artikel.

            KODE ALASAN

            Isi reason_code dengan satu kata kunci pendek dalam huruf kecil dan garis
            bawah, misalnya capaian_program, apresiasi_warga, layanan_membaik,
            keluhan_layanan, kritik_kebijakan, dugaan_pelanggaran, temuan_audit,
            proyek_terlambat, agenda_kegiatan, kutipan_prosedural, data_anggaran,
            nada_berimbang, atau sasaran_tidak_jelas.

            Isi reason_summary dengan satu kalimat bahasa Indonesia yang menjelaskan
            keputusan. Sebutkan pernyataan yang menjadi dasarnya, bukan hanya
            kesimpulannya. Tulis untuk admin yang akan memeriksa ulang, bukan untuk mesin.

            Isi requires_manual_review dengan true bila Anda ragu, sekalipun label yang
            Anda pilih bukan perlu_review.
            PROMPT;
    }

    public function down(): void
    {
        Schema::dropIfExists('kunci_gemini');
        Schema::dropIfExists('pengaturan_ai');
    }
};
