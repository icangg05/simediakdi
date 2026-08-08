<?php

namespace App\Services\Executive;

use App\Ai\Agents\ExecutiveSummaryAnalyst;
use App\Models\ExecutiveAiLog;
use App\Models\ExecutiveSummary;
use App\Models\PengaturanAi;
use App\Services\Ai\RotasiKunciGemini;
use App\Support\Waktu;
use Laravel\Ai\Enums\Lab;
use RuntimeException;
use Throwable;

final class ExecutiveSummaryService
{
    public function __construct(
        private ExecutiveAnalyticsService $analytics,
        private ExecutiveTopicService $topics,
        private RotasiKunciGemini $keys,
        private ExecutiveCache $cache,
    ) {}

    /** @param array<string, mixed> $period */
    public function generate(array $period): bool
    {
        $current = $this->analytics->metrics($period['current']['start'], $period['current']['end']);

        if ($current['total_articles'] === 0) {
            return false;
        }

        $previous = $this->analytics->metrics($period['previous']['start'], $period['previous']['end']);
        $topics = $this->topics->stored($period);
        $input = [
            'period' => [
                'type' => $period['type'],
                'start' => $period['current']['start']->toDateString(),
                'end' => $period['current']['end']->toDateString(),
            ],
            'metrics' => $current,
            'comparison' => $this->analytics->comparison($current, $previous),
            'topics' => array_map(fn ($topic) => collect($topic)->except(['article_ids'])->all(), $topics),
            'attention_items' => array_values(array_filter($topics, fn ($topic) => in_array($topic['priority_level'], ['sedang', 'tinggi'], true))),
            'top_sources' => $this->analytics->topSources($period['current']['start'], $period['current']['end'], 5),
        ];
        $fingerprint = hash('sha256', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if (ExecutiveSummary::query()
            ->where('period_type', $period['type'])
            ->whereDate('start_date', $period['current']['start'])
            ->whereDate('end_date', $period['current']['end'])
            ->where('fingerprint', $fingerprint)
            ->exists()) {
            return false;
        }

        $settings = PengaturanAi::aktif();
        $started = hrtime(true);
        $run = $this->startLog($period['type'], $settings->model, $current['total_articles']);

        try {
            $response = $this->keys->jalankan(fn () => (new ExecutiveSummaryAnalyst)->prompt(
                'Susun ringkasan eksekutif dari data berikut. Semua angka sudah final dan tidak boleh dihitung ulang:'."\n\n"
                    .json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: Lab::Gemini,
                model: (string) $settings->model,
                timeout: (int) config('ai.gemini_timeout'),
            ));

            $data = $this->validate($response->toArray());

            ExecutiveSummary::query()->updateOrCreate([
                'period_type' => $period['type'],
                'start_date' => $period['current']['start']->toDateString(),
                'end_date' => $period['current']['end']->toDateString(),
            ], [
                ...$data,
                'article_count' => $current['total_articles'],
                'fingerprint' => $fingerprint,
                'ai_provider' => 'gemini',
                'ai_model' => (string) $settings->model,
                'generated_at' => now(),
            ]);

            $this->finishLog($run, 'berhasil', $started, $response);
            $this->cache->bump();

            return true;
        } catch (Throwable $error) {
            $this->finishLog($run, 'gagal', $started, null, $error);
            throw $error;
        }
    }

    /** @param array<string, mixed> $period @return array<string, mixed>|null */
    public function stored(array $period): ?array
    {
        $exact = ExecutiveSummary::query()
            ->where('period_type', $period['type'])
            ->whereDate('start_date', $period['current']['start'])
            ->whereDate('end_date', $period['current']['end'])
            ->first();

        $summary = $exact ?? ExecutiveSummary::query()
            ->where('period_type', $period['type'])
            ->orderByDesc('generated_at')
            ->first();

        if ($summary === null) {
            return null;
        }

        return [
            'overall_tone' => $summary->overall_tone,
            'headline' => $summary->headline,
            'summary' => $summary->summary,
            'key_points' => $summary->key_points ?? [],
            'attention_required' => $summary->attention_required ?? [],
            'sentiment_summary' => $summary->sentiment_summary ?? [],
            'article_count' => $summary->article_count,
            'generated_at' => $summary->generated_at?->setTimezone(Waktu::ZONA)->toIso8601String(),
            'stale' => $exact === null,
        ];
    }

    /** @return array<string, mixed> */
    private function validate(array $data): array
    {
        $required = ['overall_tone', 'headline', 'summary', 'key_points', 'attention_required', 'sentiment_summary'];

        if (array_diff($required, array_keys($data)) !== []) {
            throw new RuntimeException('Ringkasan AI tidak memuat seluruh field wajib.');
        }

        if (! in_array($data['overall_tone'], ['positif', 'netral', 'negatif', 'campuran'], true)) {
            throw new RuntimeException('Nada umum ringkasan AI tidak valid.');
        }

        $text = mb_strtolower(implode(' ', [
            $data['headline'], $data['summary'],
            ...array_map('strval', (array) $data['key_points']),
            ...array_values((array) $data['sentiment_summary']),
        ]));

        if (str_contains($text, 'sentimen masyarakat')) {
            throw new RuntimeException('Ringkasan AI menggunakan istilah sentimen masyarakat.');
        }

        return [
            'overall_tone' => (string) $data['overall_tone'],
            'headline' => mb_substr(trim((string) $data['headline']), 0, 300),
            'summary' => trim((string) $data['summary']),
            'key_points' => array_slice(array_values((array) $data['key_points']), 0, 4),
            'attention_required' => array_slice(array_values((array) $data['attention_required']), 0, 4),
            'sentiment_summary' => (array) $data['sentiment_summary'],
        ];
    }

    private function startLog(string $period, string $model, int $count): ExecutiveAiLog
    {
        $log = ExecutiveAiLog::create([
            'task' => 'summary',
            'period_type' => $period,
            'provider' => 'gemini',
            'model' => $model,
            'input_article_count' => $count,
            'status' => 'berjalan',
        ]);

        $this->cache->bump();

        return $log;
    }

    private function finishLog(ExecutiveAiLog $log, string $status, int $started, mixed $response = null, ?Throwable $error = null): void
    {
        $log->update([
            'prompt_tokens' => $response?->usage?->promptTokens,
            'completion_tokens' => $response?->usage?->completionTokens,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'status' => $status,
            'error' => $error ? mb_substr($error->getMessage(), 0, 2000) : null,
            'generated_at' => $status === 'berhasil' ? now() : null,
        ]);

        $this->cache->bump();
    }
}
