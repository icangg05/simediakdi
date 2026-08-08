<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\PengaturanAi;
use App\Services\Ai\DTO\HasilKlasifikasi;

/**
 * Satu-satunya tempat hasil klasifikasi diterjemahkan menjadi baris database.
 *
 * Dipisah dari controller karena tiga jalur memanggilnya: tombol Klasifikasi di
 * halaman antrean, tombol yang sama di halaman arsip artikel, dan koreksi
 * relevansi manual yang meneruskan artikel ke penilaian sentimen. Menyalin
 * pemetaan kolom ke tiga tempat adalah cara paling umum ketiganya perlahan
 * berbeda, dan bedanya baru terlihat sebagai angka dashboard yang tidak bisa
 * dijelaskan.
 *
 * Relevansi bisa dikerjakan Gemini atau IndoBERT, dipilih dari halaman
 * Pengaturan. Percabangannya hanya satu baris di jalankan(), dan sengaja tidak
 * lebih: kedua penyedia mengembalikan HasilKlasifikasi yang sama, jadi seluruh
 * pemetaan kolom di bawahnya tetap satu jalur. Sentimen selalu Gemini, karena
 * IndoBERT memang tidak dilatih untuk itu.
 *
 * Dijalankan sinkron, bukan lewat antrean. Selama prompt masih disetel, hasil
 * yang muncul seketika di layar jauh lebih cepat dinilai benar atau salah
 * daripada hasil yang muncul beberapa menit kemudian. Memindahkannya ke latar
 * belakang nanti berarti membungkus kelas ini dalam satu job, bukan menulis
 * ulang logikanya.
 */
class KlasifikasiArtikel
{
    public function __construct(
        private GeminiClassificationService $ai,
        private RelevansiIndoBert $indobert,
    ) {}

    /**
     * Relevansi lalu sentimen untuk satu artikel.
     *
     * Rakitan dari dua bagian di bawahnya, bukan salinan ketiga logikanya.
     * Tombol Klasifikasi memakai ini karena admin menunggu satu hasil utuh di
     * layar. Antrean latar belakang memanggil kedua bagian terpisah, supaya ia
     * bisa berhenti di antara keduanya saat jeda Gemini belum habis.
     *
     * @return list<HasilKlasifikasi>
     */
    public function jalankan(Artikel $artikel): array
    {
        return array_merge(
            $this->jalankanRelevansi($artikel),
            $this->jalankanSentimen($artikel),
        );
    }

    /**
     * Relevansi saja, berhenti sebelum sentimen.
     *
     * Dipisah supaya antrean punya tempat berhenti di tengah. Artikel yang
     * ditolak IndoBERT selesai di sini tanpa menyentuh Gemini sama sekali, dan
     * itu yang membuat tumpukan berita tidak relevan bisa disapu cepat alih-alih
     * ikut mengantre di belakang jeda yang hanya ada untuk menjaga kuota.
     *
     * @return list<HasilKlasifikasi>
     */
    public function jalankanRelevansi(Artikel $artikel): array
    {
        $baris = $this->baris($artikel);

        // Keputusan manusia adalah keputusan akhir (F-13). Artikel yang sudah
        // diputuskan dilewati tanpa memanggil relevansi sama sekali, bukan
        // dipanggil lalu hasilnya dibuang. Panggilan yang hasilnya sudah pasti
        // tidak dipakai tetap memakan kuota free tier.
        if ($baris?->relevan_manual !== null) {
            if (! $baris->relevan) {
                $artikel->update(['status_proses' => 'tidak_relevan']);
            }

            return [];
        }

        // Penyedia dibaca sekarang, bukan saat job dibuat. Itu yang membuat
        // pergantian opsi di halaman Pengaturan langsung berlaku untuk seluruh
        // pekerjaan yang masih mengantre, tanpa antreannya perlu dikosongkan.
        // Job hanya membawa id barisnya, dan PengaturanAi sengaja dibaca tanpa
        // cache.
        $relevansi = PengaturanAi::aktif()->penyedia_relevansi === 'indobert'
            ? $this->indobert->relevansi($artikel)
            : $this->ai->relevansi($artikel);

        $relevan = $relevansi->label === 'relevan';

        // Baris analisis dibuat untuk artikel relevan maupun tidak. Yang tidak
        // relevan tetap perlu barisnya, karena di situlah alasan artikel tidak
        // muncul di dashboard bisa ditelusuri. Menghapusnya berarti tidak ada
        // bedanya antara "dinilai tidak relevan" dan "belum pernah dinilai".
        $kolom = [
            'relevan' => $relevan,
            'provider' => $relevansi->penyedia,
            'reason_code' => $relevansi->alasanKode,
            'reason_summary' => $relevansi->alasanRingkas,
            'evidence' => $relevansi->bukti,
            'prompt_version' => $relevansi->versiPrompt,
        ];

        // Jawaban tidak relevan membatalkan sentimen yang pernah dinilai
        // sebelumnya. Tanpa ini barisnya menyimpan dua jawaban yang bertentangan
        // sekaligus, dan yang lama menang di layar: `provider` sudah berbunyi
        // gemini sementara `model_versi` masih menyebut IndoBERT dari pipeline
        // lama, sehingga halaman detail berbunyi "Dinilai gemini:
        // indobert-sentiment-classifier-2.0.0".
        if (! $relevan) {
            $kolom += AnalisisSentimen::SENTIMEN_KOSONG;
        }

        AnalisisSentimen::updateOrCreate(['artikel_id' => $artikel->id], $kolom);

        // `dianalisis`, bukan `selesai`, untuk yang relevan. Sentimennya belum
        // dinilai, dan antrean boleh berhenti di sini selama berjam-jam kalau
        // jatah Gemini sedang habis. Status yang sudah berbunyi selesai membuat
        // artikel setengah jadi terhitung sebagai pekerjaan yang beres.
        // `dianalisis` sudah masuk kelompok tahap Selesai di layar dan sudah
        // dikenali penyisir antrean sebagai relevan yang labelnya belum ada.
        $artikel->update([
            'status_proses' => match (true) {
                $relevan => 'dianalisis',
                $relevansi->label === 'perlu_review' => 'perlu_review',
                default => 'tidak_relevan',
            },
        ]);

        return [$relevansi];
    }

    /**
     * Apakah artikel ini masih menunggu penilaian sentimen.
     *
     * Dibaca antrean untuk memutuskan apakah jeda Gemini perlu ditunggu.
     * Sentimen selalu Gemini, jadi jawaban ya di sini berarti pekerjaan
     * berikutnya memang akan memakan kuota.
     */
    public function perluSentimen(Artikel $artikel): bool
    {
        return (bool) $this->baris($artikel)?->relevan;
    }

    /**
     * Apakah salah satu hasil datang dari Gemini.
     *
     * Dibaca dari hasilnya, bukan dari pengaturan yang sedang aktif. Satu
     * artikel bisa menghasilkan dua keputusan dari dua penilai berbeda, dan
     * yang menentukan pemakaian kuota hanya yang benar-benar terpanggil.
     *
     * @param  list<HasilKlasifikasi>  $hasil
     */
    public static function pakaiGemini(array $hasil): bool
    {
        foreach ($hasil as $satu) {
            if ($satu->penyedia === 'gemini') {
                return true;
            }
        }

        return false;
    }

    /**
     * Sentimen saja, untuk artikel yang relevansinya sudah diputuskan manusia.
     *
     * @return list<HasilKlasifikasi>
     */
    public function jalankanSentimen(Artikel $artikel): array
    {
        $baris = $this->baris($artikel);

        if ($baris === null || ! $baris->relevan) {
            return [];
        }

        $hasil = [$this->sentimen($artikel, $baris)];

        $artikel->update(['status_proses' => 'selesai']);

        return $hasil;
    }

    private function sentimen(Artikel $artikel, AnalisisSentimen $baris): HasilKlasifikasi
    {
        $hasil = $this->ai->sentimen($artikel);

        $baris->update([
            // `perlu_review` bukan label sentimen. Constraint database hanya
            // menerima negatif, netral, dan positif, dan itu memang benar:
            // ketiganya yang dijumlahkan dashboard. Keraguan model disimpan
            // sebagai label kosong ditambah penanda antrean, bukan dipaksa
            // menjadi netral. Netral berarti "sudah dinilai dan hasilnya
            // datar", bukan "belum tahu".
            'label_model' => $hasil->label === 'perlu_review' ? null : $hasil->label,
            // Koreksi manusia adalah kepastian. Baris yang sudah dikoreksi
            // tidak pernah kembali masuk antrean review hanya karena model
            // dijalankan ulang dan kali ini ragu.
            'perlu_review' => $baris->label_manual === null && $hasil->perluReview,
            'model_versi' => $hasil->model,
            // `provider` sengaja tidak ditulis ulang di sini. Satu baris punya
            // dua keputusan tetapi hanya satu kolom penyedia, dan yang perlu
            // dijawabnya adalah siapa yang memutuskan relevansi. Sentimen
            // selalu Gemini, jadi menuliskannya di sini tidak menambah satu pun
            // keterangan baru, sementara menghapus tanda IndoBERT persis pada
            // artikel yang lolos saringan, yaitu artikel yang paling perlu
            // ditelusuri saat menilai apakah modelnya layak dipercaya.
            'reason_code' => $hasil->alasanKode,
            'reason_summary' => $hasil->alasanRingkas,
            'evidence' => $hasil->bukti,
            'prompt_version' => $hasil->versiPrompt,
            'dianalisis_at' => now(),
        ]);

        return $hasil;
    }

    private function baris(Artikel $artikel): ?AnalisisSentimen
    {
        return AnalisisSentimen::where('artikel_id', $artikel->id)->first();
    }
}
