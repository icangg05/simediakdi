<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\LabelSentimen;
use App\Jobs\KirimAlertBeritaNegatif;
use App\Models\AnalisisSentimen;
use App\Models\Artikel;
use App\Models\PengaturanAi;
use App\Services\Ai\DTO\HasilKlasifikasi;
use InvalidArgumentException;

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
 * Relevansi bisa dikerjakan Gemini atau IndoBERT. Pekerjaan otomatis mengikuti
 * halaman Pengaturan, sedangkan tombol di halaman Artikel boleh menentukan
 * jalurnya secara eksplisit. Kedua penyedia mengembalikan HasilKlasifikasi yang
 * sama, jadi seluruh pemetaan kolom di bawahnya tetap satu jalur. Sentimen
 * selalu Gemini, karena IndoBERT memang tidak dilatih untuk itu.
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
    public function jalankan(Artikel $artikel, ?string $penyediaRelevansi = null): array
    {
        return array_merge(
            $this->jalankanRelevansi($artikel, $penyediaRelevansi),
            $this->jalankanSentimen($artikel),
        );
    }

    /** @return list<HasilKlasifikasi> */
    public function jalankanManual(Artikel $artikel, ?string $penyediaRelevansi = null): array
    {
        return $this->jalankanDenganJedaKunci($artikel, $penyediaRelevansi);
    }

    /**
     * Menjalankan klasifikasi dengan pemilih kunci yang sudah menganggur 15 detik.
     *
     * Dipakai tombol manual dan job antrean agar keduanya tidak punya dua
     * aturan rotasi yang perlahan berbeda.
     *
     * @return list<HasilKlasifikasi>
     */
    public function jalankanDenganJedaKunci(Artikel $artikel, ?string $penyediaRelevansi = null): array
    {
        $penyedia = $penyediaRelevansi ?? PengaturanAi::aktif()->penyedia_relevansi;

        return $this->ai->dalamJedaKunci(
            fn (): array => $this->lanjutkanDenganJedaKunci($artikel, $penyedia),
        );
    }

    /**
     * Melanjutkan artikel yang relevansinya sudah tersimpan tetapi tertahan
     * sebelum sentimen karena seluruh kunci sedang cooldown.
     *
     * @return list<HasilKlasifikasi>
     */
    private function lanjutkanDenganJedaKunci(Artikel $artikel, string $penyediaRelevansi): array
    {
        $baris = $this->baris($artikel);

        if ($artikel->status_proses === 'dianalisis'
            && $baris?->relevan
            && $baris->label_model === null) {
            return $this->jalankanSentimen($artikel);
        }

        return $this->jalankan($artikel, $penyediaRelevansi);
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
    public function jalankanRelevansi(Artikel $artikel, ?string $penyediaRelevansi = null): array
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

        // Tanpa override, penyedia dibaca sekarang, bukan saat job dibuat. Itu
        // membuat pergantian Pengaturan langsung berlaku untuk seluruh antrean
        // tanpa perlu mengosongkannya. Halaman Artikel mengirim override agar
        // dua tombol manualnya selalu tersedia, apa pun pengaturan otomatisnya.
        $penyedia = $penyediaRelevansi ?? PengaturanAi::aktif()->penyedia_relevansi;

        $relevansi = match ($penyedia) {
            'gemini' => $this->ai->relevansi($artikel),
            'indobert' => $this->indobert->relevansi($artikel),
            default => throw new InvalidArgumentException("Penyedia relevansi {$penyedia} tidak dikenali."),
        };

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

    /** @return list<HasilKlasifikasi> */
    public function jalankanSentimenManual(Artikel $artikel): array
    {
        return $this->ai->dalamKlasifikasiManual(
            fn (): array => $this->jalankanSentimen($artikel),
        );
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

        // Berita negatif dikabarkan saat itu juga, bukan menunggu penilaian
        // berkala 15 menit sekali. Ini satu-satunya titik seluruh jalur
        // klasifikasi menuliskan label sentimen, jadi memasangnya di sini
        // membuat tombol di layar, antrean latar belakang, dan penilaian ulang
        // memicu hal yang sama tanpa satu pun pemanggil perlu mengingatnya.
        //
        // `refresh` diperlukan: `label_efektif` kolom generated Postgres, dan
        // nilainya baru ada setelah dibaca ulang dari database.
        if ($baris->refresh()->label_efektif === LabelSentimen::Negatif) {
            KirimAlertBeritaNegatif::dispatch($artikel->id);
        }

        return $hasil;
    }

    private function baris(Artikel $artikel): ?AnalisisSentimen
    {
        return AnalisisSentimen::where('artikel_id', $artikel->id)->first();
    }
}
