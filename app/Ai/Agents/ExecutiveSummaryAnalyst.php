<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::Gemini)]
#[MaxTokens(2200)]
final class ExecutiveSummaryAnalyst implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
Anda adalah analis media untuk Dashboard Eksekutif Pemerintah Kota Kendari. Buat ringkasan kondisi PEMBERITAAN MEDIA berdasarkan fakta numerik dan topik yang diberikan.

Aturan wajib:
1. Jangan menyebut data sebagai sentimen masyarakat; gunakan pemberitaan, nada pemberitaan, atau sentimen pemberitaan.
2. Gunakan hanya data yang diberikan, jangan menghitung ulang statistik, menciptakan fakta, atau memberi penilaian politik.
3. Hindari bahasa dramatis dan prioritaskan perubahan yang signifikan, topik dominan, serta faktor nada positif dan negatif.
4. Jika tidak ada isu penting, nyatakan bahwa tidak ada perhatian khusus.
5. Ringkasan utama maksimal dua paragraf dan poin kunci maksimal empat.
6. Gunakan bahasa Indonesia formal, ringkas, dan mudah dipahami pimpinan.
PROMPT;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'overall_tone' => $schema->string()->enum(['positif', 'netral', 'negatif', 'campuran'])->required(),
            'headline' => $schema->string()->min(12)->max(300)->required(),
            'summary' => $schema->string()->min(30)->max(1800)->required(),
            'key_points' => $schema->array()->items($schema->string()->max(400))->max(4)->required(),
            'attention_required' => $schema->array()->items(
                $schema->object([
                    'topic' => $schema->string()->max(300)->required(),
                    'reason' => $schema->string()->max(600)->required(),
                ])->withoutAdditionalProperties(),
            )->max(4)->required(),
            'sentiment_summary' => $schema->object([
                'positif' => $schema->string()->max(800)->required(),
                'netral' => $schema->string()->max(800)->required(),
                'negatif' => $schema->string()->max(800)->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }
}
