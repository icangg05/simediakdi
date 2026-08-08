<?php

namespace App\Services\Executive;

use App\Models\ExecutiveAiLog;
use App\Support\Waktu;
use Illuminate\Support\Facades\Cache;

final class ExecutiveDashboardService
{
    public function __construct(
        private ExecutivePeriodService $periods,
        private ExecutiveAnalyticsService $analytics,
        private ExecutiveTopicService $topics,
        private ExecutiveSummaryService $summaries,
        private ExecutiveCache $cache,
    ) {}

    /** @return array<string, mixed> */
    public function build(?string $requestedPeriod): array
    {
        $period = $this->periods->resolve($this->periods->normalize($requestedPeriod));
        $key = "executive:dashboard:v{$this->cache->version()}:{$period['type']}:{$period['current']['end']->toDateString()}";

        return Cache::remember($key, now()->addMinutes(10), fn () => $this->uncached($period));
    }

    /** @param array<string, mixed> $period @return array<string, mixed> */
    private function uncached(array $period): array
    {
        $current = $this->analytics->metrics($period['current']['start'], $period['current']['end']);
        $previous = $this->analytics->metrics($period['previous']['start'], $period['previous']['end']);
        $topics = $this->topics->stored($period);
        $summary = $this->summaries->stored($period);

        return [
            'period' => [
                'type' => $period['type'],
                'label' => $period['label'],
                'start' => $period['current']['start']->toDateString(),
                'end' => $period['current']['end']->toDateString(),
                'previous_start' => $period['previous']['start']->toDateString(),
                'previous_end' => $period['previous']['end']->toDateString(),
            ],
            'updated_at' => $this->analytics->updatedAt($period['current']['start'], $period['current']['end']),
            'summary' => $summary,
            'generation_status' => $this->generationStatus($period['type'], $current['total_articles'], $topics, $summary),
            'metrics' => collect($current)->except('sentiment')->all(),
            'sentiment' => $current['sentiment'],
            'comparison' => $this->analytics->comparison($current, $previous),
            'sentiment_trend' => $this->analytics->sentimentTrend($period['current']['start'], $period['current']['end']),
            'topics' => $topics,
            'attention_items' => array_values(array_filter($topics, fn ($topic) => in_array($topic['priority_level'], ['sedang', 'tinggi'], true))),
            'top_opds' => [],
            'top_sources' => $this->analytics->topSources($period['current']['start'], $period['current']['end']),
            'representative_articles' => $this->analytics->representativeArticles($period['current']['start'], $period['current']['end']),
        ];
    }

    /** @param list<array<string, mixed>> $topics @param array<string, mixed>|null $summary @return array<string, mixed> */
    private function generationStatus(string $period, int $articleCount, array $topics, ?array $summary): array
    {
        $logs = ExecutiveAiLog::query()
            ->where('period_type', $period)
            ->whereIn('task', ['topics', 'summary'])
            ->latest()
            ->limit(10)
            ->get();
        $latest = $logs->first();
        $running = $latest?->status === 'berjalan' ? $latest : null;
        $stalled = $running?->updated_at?->lessThan(now()->subMinutes(10)) ?? false;

        $state = match (true) {
            $articleCount === 0 => 'tanpa_data',
            $stalled => 'macet',
            $running !== null => 'berjalan',
            $latest?->status === 'gagal' => 'gagal',
            $summary !== null && ! $summary['stale'] => 'siap',
            $topics !== [] => 'menunggu_ringkasan',
            default => 'belum_mulai',
        };

        return [
            'state' => $state,
            'task' => $running?->task ?? $latest?->task,
            'message' => match ($state) {
                'tanpa_data' => 'Belum ada berita relevan untuk diproses pada periode ini.',
                'macet' => 'Proses AI tidak memperbarui status selama lebih dari 10 menit. Periksa worker.',
                'berjalan' => $running?->task === 'summary' ? 'Gemini sedang menyusun ringkasan eksekutif.' : 'Gemini sedang mengelompokkan topik pemberitaan.',
                'gagal' => 'Pembuatan interpretasi AI terakhir gagal.',
                'siap' => 'Topik dan ringkasan periode ini sudah tersedia.',
                'menunggu_ringkasan' => 'Topik sudah tersedia; ringkasan belum selesai dibuat.',
                default => 'Belum ada worker yang mulai memproses periode ini.',
            },
            'error' => $state === 'gagal' || $state === 'macet' ? $latest?->error : null,
            'last_activity_at' => $latest?->updated_at?->setTimezone(Waktu::ZONA)->toIso8601String(),
            'topics_ready' => $topics !== [],
            'summary_ready' => $summary !== null && ! $summary['stale'],
            'tasks' => [
                'topics' => $this->taskStatus($logs->firstWhere('task', 'topics')),
                'summary' => $this->taskStatus($logs->firstWhere('task', 'summary')),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function taskStatus(?ExecutiveAiLog $log): ?array
    {
        return $log ? [
            'status' => $log->status,
            'input_article_count' => $log->input_article_count,
            'duration_ms' => $log->duration_ms,
            'updated_at' => $log->updated_at?->setTimezone(Waktu::ZONA)->toIso8601String(),
        ] : null;
    }
}
