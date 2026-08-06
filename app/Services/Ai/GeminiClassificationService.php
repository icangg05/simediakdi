<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Ai\Agents\RelevanceClassifier;
use App\Ai\Agents\SentimentClassifier;
use App\Models\Artikel;
use App\Models\PengaturanAi;
use App\Services\Ai\DTO\HasilKlasifikasi;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;

/**
 * Satu-satunya tempat aplikasi memanggil Gemini. Dokumen 13 bagian 10.
 *
 * Tidak ada client HTTP buatan sendiri dan tidak ada perantara FastAPI. Laravel
 * AI SDK sudah menangani autentikasi, structured output, dan pemetaan galat
 * penyedia menjadi exception yang bisa dikenali kebijakan cadangan.
 *
 * Nama model dan versi prompt dibaca dari `pengaturan_ai`, bukan dari config.
 * Keduanya ikut disimpan pada setiap prediksi, jadi hasil dari dua setelan
 * berbeda tidak pernah tercampur tanpa bisa dibedakan.
 */
class GeminiClassificationService
{
    public function __construct(
        private ArtikelPromptBuilder $prompt,
        private EvidenceValidator $bukti,
        private RotasiKunciGemini $rotasi,
    ) {}

    public function relevansi(Artikel $artikel): HasilKlasifikasi
    {
        $pengaturan = PengaturanAi::aktif();

        return $this->klasifikasi(
            new RelevanceClassifier,
            $artikel,
            $pengaturan,
            $pengaturan->versiRelevansi(),
        );
    }

    public function sentimen(Artikel $artikel): HasilKlasifikasi
    {
        $pengaturan = PengaturanAi::aktif();

        return $this->klasifikasi(
            new SentimentClassifier,
            $artikel,
            $pengaturan,
            $pengaturan->versiSentimen(),
        );
    }

    private function klasifikasi(
        Agent $agent,
        Artikel $artikel,
        PengaturanAi $pengaturan,
        string $versiPrompt,
    ): HasilKlasifikasi {
        $model = (string) $pengaturan->model;

        $mulai = hrtime(true);

        // Dibungkus rotasi kunci: kunci yang kena limit ditandai dan panggilan
        // diulang dengan kunci berikutnya, bukan digagalkan.
        $tanggapan = $this->rotasi->jalankan(fn () => $agent->prompt(
            $this->prompt->build($artikel),
            provider: Lab::Gemini,
            model: $model,
            timeout: (int) config('ai.gemini_timeout'),
        ));

        $latensi = (int) ((hrtime(true) - $mulai) / 1_000_000);

        $daftarBukti = array_values(array_map(
            strval(...),
            (array) ($tanggapan['evidence'] ?? []),
        ));

        // Bukti diperiksa terhadap teks yang benar-benar dikirim, bukan terhadap
        // kolom `isi` utuh. Artikel yang dipotong di batas huruf tidak boleh
        // membuat kutipan dari ekor yang tidak terkirim dinyatakan valid.
        $buktiTidakValid = $this->bukti->periksa(
            $daftarBukti,
            $this->prompt->teksTerkirim($artikel),
        );

        if ($buktiTidakValid !== null) {
            return new HasilKlasifikasi(
                label: 'perlu_review',
                alasanKode: $buktiTidakValid,
                alasanRingkas: 'Kutipan bukti dari model tidak ditemukan di isi artikel, '
                    .'jadi labelnya tidak bisa dipertanggungjawabkan. Label asli: '
                    .(string) ($tanggapan['label'] ?? 'tidak diketahui').'.',
                bukti: $daftarBukti,
                perluReview: true,
                penyedia: 'gemini',
                model: $model,
                latensiMs: $latensi,
                versiPrompt: $versiPrompt,
            );
        }

        $label = (string) $tanggapan['label'];

        return new HasilKlasifikasi(
            label: $label,
            alasanKode: (string) $tanggapan['reason_code'],
            alasanRingkas: (string) $tanggapan['reason_summary'],
            bukti: $daftarBukti,
            // Model yang menyatakan dirinya ragu selalu dipercaya soal
            // keraguannya, sekalipun label yang dipilihnya tegas.
            perluReview: $label === 'perlu_review'
                || (bool) ($tanggapan['requires_manual_review'] ?? false),
            penyedia: 'gemini',
            model: $model,
            latensiMs: $latensi,
            versiPrompt: $versiPrompt,
        );
    }
}
