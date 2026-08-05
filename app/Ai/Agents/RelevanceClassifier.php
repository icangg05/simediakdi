<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\File;
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
 * Dipakai stateless, satu artikel satu konteks satu permintaan. SIMEDIA tidak
 * membutuhkan percakapan berkelanjutan, dan menyimpan riwayat percakapan justru
 * membuat hasil satu artikel bergantung pada artikel yang kebetulan dinilai
 * sebelumnya.
 *
 * Instruksinya dibaca dari berkas, bukan ditulis di kelas ini. Prompt adalah
 * bagian yang paling sering disunting dan paling perlu dibandingkan antar
 * versi, dan keduanya jauh lebih mudah kalau ia berupa berkas teks yang bisa
 * dibaca diff Git tanpa ikut membaca kode PHP di sekitarnya.
 */
#[Provider(Lab::Gemini)]
#[MaxTokens(600)]
final class RelevanceClassifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return File::get(resource_path(
            'prompts/'.config('ai.prompt.relevansi').'.txt'
        ));
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
