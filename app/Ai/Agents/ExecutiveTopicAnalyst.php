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
#[MaxTokens(3000)]
final class ExecutiveTopicAnalyst implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
Anda adalah analis media Pemerintah Kota Kendari. Kelompokkan artikel berdasarkan isu, peristiwa, kebijakan, program, kritik, atau masalah yang benar-benar sama.

Aturan wajib:
1. Gunakan hanya data yang diberikan dan jangan membuat atau mengubah article_id.
2. Satu artikel hanya boleh masuk ke satu topik utama.
3. Nama topik harus berupa kalimat singkat yang menjelaskan pembahasan, bukan satu atau dua kata kunci.
4. Jangan menggabungkan artikel hanya karena memiliki satu kata yang sama.
5. Jangan menghitung statistik dan jangan membuat kesimpulan yang tidak didukung artikel.
6. Abaikan artikel yang tidak cukup mewakili topik penting bila jumlah topik sudah mencapai batas.
7. Gunakan bahasa Indonesia formal dan ringkas.
PROMPT;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'topics' => $schema->array()->items(
                $schema->object([
                    'title' => $schema->string()->min(12)->max(300)->required(),
                    'summary' => $schema->string()->min(20)->max(800)->required(),
                    'article_ids' => $schema->array()
                        ->items($schema->integer())
                        ->min(1)
                        ->unique()
                        ->required(),
                ])->withoutAdditionalProperties(),
            )->min(1)->max(8)->required(),
        ];
    }
}
