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
 * Penilai sentimen artikel terhadap satu konteks pantauan. Dokumen 13 bagian 8.
 *
 * Hanya dipanggil untuk pasangan artikel dan konteks yang relevansinya sudah
 * final dan berbunyi relevan. Sentimen yang akurat atas artikel yang salah
 * tetap salah, dan salahnya masuk ke dashboard sebagai angka yang tampak wajar.
 *
 * Instruksinya dibaca dari `pengaturan_ai`, sama seperti penilai relevansi.
 *
 * `perlu_review` sengaja ada di daftar enum, sedangkan kolom `label_model` di
 * database hanya menerima negatif, netral, dan positif. Pemetaannya dilakukan
 * di job: perlu_review berarti label dikosongkan dan barisnya ditandai untuk
 * diperiksa manusia, bukan dipaksa masuk salah satu dari tiga nilai itu.
 */
#[Provider(Lab::Gemini)]
#[MaxTokens(600)]
final class SentimentClassifier implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return PengaturanAi::aktif()->prompt_sentimen;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'label' => $schema->string()
                ->enum(['positif', 'netral', 'negatif', 'perlu_review'])
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
