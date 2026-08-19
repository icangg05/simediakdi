<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Models\PengaturanAi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Penilai relevansi artikel terhadap satu konteks pantauan. Dokumen 13 bagian 7.
 *
 * Dipakai stateless, satu artikel satu konteks satu permintaan. SIMAK tidak
 * membutuhkan percakapan berkelanjutan, dan menyimpan riwayat percakapan justru
 * membuat hasil satu artikel bergantung pada artikel yang kebetulan dinilai
 * sebelumnya.
 *
 * Instruksinya dibaca dari `pengaturan_ai`, bukan ditulis di kelas ini dan
 * bukan lagi dari berkas. Prompt adalah bagian yang paling sering disunting,
 * dan menyetelnya lewat deploy berarti menunggu rilis untuk memperbaiki satu
 * kalimat. Isi awalnya ditulis di migration yang membuat tabel itu.
 *
 * Versi promptnya ikut disimpan pada setiap prediksi, jadi hasil dari dua
 * prompt berbeda tetap bisa dibedakan meski promptnya sudah berubah.
 */
#[Provider(Lab::Gemini)]
#[MaxTokens(600)]
final class RelevanceClassifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return PengaturanAi::aktif()->prompt_relevansi;
    }

    /**
     * Structured output, bukan teks bebas yang di-parse belakangan.
     *
     * Skema ini yang membuat label selalu berupa salah satu dari tiga nilai
     * yang dikenal database. Model yang menjawab "kemungkinan relevan" dalam
     * kalimat bebas akan selalu terjadi sesekali, dan tanpa skema kegagalannya
     * baru terlihat saat baris ditolak Postgres jauh di hilir.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'label' => $schema->string()
                ->enum(['relevan', 'tidak_relevan', 'perlu_review'])
                ->required(),

            'reason_code' => $schema->string()->required(),

            'reason_summary' => $schema->string()->required(),

            'evidence' => $schema->array()
                ->items($schema->string())
                ->required(),

            'requires_manual_review' => $schema->boolean()->required(),
        ];
    }
}
