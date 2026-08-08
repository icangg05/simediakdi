<?php

namespace App\Services\Executive;

use App\Ai\Agents\ExecutiveTopicAnalyst;
use App\Models\Artikel;
use App\Models\ExecutiveAiLog;
use App\Models\ExecutiveTopic;
use App\Models\PengaturanAi;
use App\Services\Ai\RotasiKunciGemini;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Enums\Lab;
use RuntimeException;
use Throwable;

final class ExecutiveTopicService
{
    private const MAX_INPUT_ARTICLES = 80;

    public function __construct(
        private ExecutivePeriodService $periods,
        private RotasiKunciGemini $keys,
        private ExecutiveCache $cache,
    ) {}

    /** @param array<string, mixed> $period */
    public function generate(array $period): bool
    {
        $articles = $this->representativeInput($period['current']['start'], $period['current']['end']);

        if ($articles->isEmpty()) {
            return false;
        }

        $fingerprint = $this->fingerprint($period['current']['start'], $period['current']['end']);

        if (ExecutiveTopic::query()
            ->where('period_type', $period['type'])
            ->whereDate('start_date', $period['current']['start'])
            ->whereDate('end_date', $period['current']['end'])
            ->where('fingerprint', $fingerprint)
            ->exists()) {
            return false;
        }

        $settings = PengaturanAi::aktif();
        $started = hrtime(true);
        $run = $this->startLog($period['type'], $settings->model, $articles->count());

        try {
            $response = $this->keys->jalankan(fn () => (new ExecutiveTopicAnalyst)->prompt(
                $this->prompt($articles, $this->periods->topicLimit($period['type'])),
                provider: Lab::Gemini,
                model: (string) $settings->model,
                timeout: (int) config('ai.gemini_timeout'),
            ));

            $topics = $this->validate((array) ($response['topics'] ?? []), $articles, $this->periods->topicLimit($period['type']));
            $rows = $this->calculate($topics, $articles, $period, $fingerprint);

            DB::transaction(function () use ($period, $rows): void {
                ExecutiveTopic::query()
                    ->where('period_type', $period['type'])
                    ->whereDate('start_date', $period['current']['start'])
                    ->whereDate('end_date', $period['current']['end'])
                    ->delete();

                ExecutiveTopic::query()->insert($rows);
            });

            $this->finishLog($run, 'berhasil', $started, $response);
            $this->cache->bump();

            return true;
        } catch (Throwable $error) {
            $this->finishLog($run, 'gagal', $started, null, $error);
            throw $error;
        }
    }

    /** @param array<string, mixed> $period @return list<array<string, mixed>> */
    public function stored(array $period): array
    {
        return ExecutiveTopic::query()
            ->where('period_type', $period['type'])
            ->whereDate('start_date', $period['current']['start'])
            ->whereDate('end_date', $period['current']['end'])
            ->orderByRaw('(article_count + source_count * 2 + negative_count * 0.5) DESC')
            ->get()
            ->map(fn (ExecutiveTopic $topic) => [
                'id' => $topic->id,
                'title' => $topic->title,
                'summary' => $topic->summary,
                'article_count' => $topic->article_count,
                'source_count' => $topic->source_count,
                'sentiment' => [
                    'positif' => $this->share($topic->positive_count, $topic->article_count),
                    'netral' => $this->share($topic->neutral_count, $topic->article_count),
                    'negatif' => $this->share($topic->negative_count, $topic->article_count),
                ],
                'dominant_sentiment' => $topic->dominant_sentiment,
                'trend' => $topic->trend,
                'priority_score' => $topic->priority_score,
                'priority_level' => $topic->priority_level,
                'article_ids' => $topic->article_ids,
                'generated_at' => $topic->generated_at?->setTimezone(Waktu::ZONA)->toIso8601String(),
            ])
            ->all();
    }

    /** @return Collection<int, Artikel> */
    private function representativeInput(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $articles = Artikel::query()
            ->relevanBerlabel()
            ->with(['media:id,nama', 'analisisSentimen' => fn ($query) => $query->where('relevan', true)])
            ->whereBetween('diambil_at', [Waktu::awalHari($start->toDateString()), Waktu::akhirHari($end->toDateString())])
            ->orderByDesc('diambil_at')
            ->limit(240)
            ->get(['id', 'media_id', 'judul', 'ringkasan', 'diambil_at', 'updated_at'])
            ->unique(fn (Artikel $article) => mb_strtolower(trim($article->judul)))
            ->sortBy(fn (Artikel $article) => match ($article->analisisSentimen->first()?->label_efektif?->value) {
                'negatif' => 0,
                'netral' => 1,
                default => 2,
            });

        $selected = collect();
        $perSource = [];

        foreach ($articles as $article) {
            $source = (string) ($article->media_id ?? 'unknown');

            if (($perSource[$source] ?? 0) >= 4 && $selected->count() < 50) {
                continue;
            }

            $selected->push($article);
            $perSource[$source] = ($perSource[$source] ?? 0) + 1;

            if ($selected->count() >= self::MAX_INPUT_ARTICLES) {
                break;
            }
        }

        // Jika sumbernya sedikit, batas empat per media di putaran pertama
        // tidak boleh membuat input berhenti pada empat artikel saja. Putaran
        // kedua mengisi sisa kapasitas tanpa batas media, tetap dalam urutan
        // prioritas negatif, netral, lalu positif.
        if ($selected->count() < self::MAX_INPUT_ARTICLES) {
            $selectedIds = $selected->pluck('id')->all();

            foreach ($articles->whereNotIn('id', $selectedIds) as $article) {
                $selected->push($article);

                if ($selected->count() >= self::MAX_INPUT_ARTICLES) {
                    break;
                }
            }
        }

        return $selected->values();
    }

    /** @param Collection<int, Artikel> $articles */
    private function prompt(Collection $articles, int $limit): string
    {
        $allowedIds = $articles->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $payload = $articles->map(fn (Artikel $article) => [
            'id' => $article->id,
            'title' => $article->judul,
            'summary' => $article->ringkasan,
            'sentiment' => $article->analisisSentimen->first()?->label_efektif?->value,
            'source' => $article->media?->nama,
            'date' => Waktu::tanggalWita($article->diambil_at),
        ])->values()->all();

        return "Buat paling banyak {$limit} topik utama dari artikel berikut. Jangan hitung statistik; kembalikan pengelompokan ID saja. "
            .'Setiap article_id pada jawaban WAJIB merupakan anggota persis dari allowed_article_ids. '
            .'Periksa kembali seluruh ID sebelum menjawab dan abaikan artikel bila ID-nya tidak ada dalam daftar.'
            ."\n\nallowed_article_ids: "
            .json_encode($allowedIds, JSON_THROW_ON_ERROR)
            ."\n\narticles: "
            .json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<int, mixed>  $topics
     * @param  Collection<int, Artikel>  $articles
     * @return list<array{title: string, summary: string, article_ids: list<int>}>
     */
    private function validate(array $topics, Collection $articles, int $limit): array
    {
        if ($topics === []) {
            throw new RuntimeException('AI tidak mengembalikan satu pun topik.');
        }

        if (count($topics) > $limit) {
            throw new RuntimeException('AI mengembalikan topik melebihi batas periode.');
        }

        $allowed = $articles->pluck('id')->map(fn ($id) => (int) $id)->all();
        $seen = [];
        $valid = [];

        foreach ($topics as $topic) {
            $title = trim((string) ($topic['title'] ?? ''));
            $summary = trim((string) ($topic['summary'] ?? ''));
            $ids = array_values(array_unique(array_map('intval', (array) ($topic['article_ids'] ?? []))));

            if (str_word_count($title) < 4 || mb_strlen($title) > 300 || $summary === '' || $ids === []) {
                throw new RuntimeException('AI mengembalikan bentuk topik yang tidak valid.');
            }

            if (($invalid = array_diff($ids, $allowed)) !== []) {
                throw new RuntimeException('AI mengembalikan article_id yang tidak valid: '.implode(', ', $invalid).'.');
            }

            if (array_intersect($ids, $seen) !== []) {
                throw new RuntimeException('Satu artikel dimasukkan ke lebih dari satu topik.');
            }

            $seen = array_merge($seen, $ids);
            $valid[] = ['title' => $title, 'summary' => $summary, 'article_ids' => $ids];
        }

        return $valid;
    }

    /** @param list<array{title: string, summary: string, article_ids: list<int>}> $topics @param Collection<int, Artikel> $articles @param array<string, mixed> $period */
    private function calculate(array $topics, Collection $articles, array $period, string $fingerprint): array
    {
        $now = now();
        $rows = [];

        foreach ($topics as $topic) {
            $members = $articles->whereIn('id', $topic['article_ids']);
            $counts = collect(['positif' => 0, 'netral' => 0, 'negatif' => 0]);

            foreach ($members as $article) {
                $label = $article->analisisSentimen->first()?->label_efektif?->value;
                if ($label !== null) {
                    $counts[$label] = $counts[$label] + 1;
                }
            }

            $sourceCount = $members->pluck('media_id')->filter()->unique()->count();
            $previous = ExecutiveTopic::query()
                ->where('period_type', $period['type'])
                ->whereDate('start_date', $period['previous']['start'])
                ->whereDate('end_date', $period['previous']['end'])
                ->whereRaw('lower(title) = ?', [mb_strtolower($topic['title'])])
                ->first();
            $growth = $previous && $previous->article_count > 0
                ? (($members->count() - $previous->article_count) / $previous->article_count) * 100
                : null;
            $consecutive = $this->consecutiveDays($members);
            $score = $this->priorityScore($counts['negatif'], $sourceCount, $growth, $consecutive);
            $dominant = $counts->sortDesc()->keys()->first();

            $rows[] = [
                'period_type' => $period['type'],
                'start_date' => $period['current']['start']->toDateString(),
                'end_date' => $period['current']['end']->toDateString(),
                'title' => $topic['title'],
                'summary' => $topic['summary'],
                'article_count' => $members->count(),
                'positive_count' => $counts['positif'],
                'neutral_count' => $counts['netral'],
                'negative_count' => $counts['negatif'],
                'source_count' => $sourceCount,
                'dominant_sentiment' => $dominant,
                'trend' => $previous === null ? 'baru' : ($growth >= 20 ? 'meningkat' : ($growth <= -20 ? 'menurun' : 'stabil')),
                'priority_score' => $score,
                'priority_level' => $score >= 6 ? 'tinggi' : ($score >= 3 ? 'sedang' : 'rendah'),
                'article_ids' => json_encode($topic['article_ids'], JSON_THROW_ON_ERROR),
                'fingerprint' => $fingerprint,
                'generated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        usort($rows, fn ($a, $b) => ($b['article_count'] + $b['source_count'] * 2 + $b['negative_count'] * .5) <=> ($a['article_count'] + $a['source_count'] * 2 + $a['negative_count'] * .5));

        return $rows;
    }

    private function fingerprint(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $row = DB::table('artikel as a')
            ->join('analisis_sentimen as s', function ($join) {
                $join->on('s.artikel_id', '=', 'a.id')
                    ->where('s.relevan', true)
                    ->whereNotNull('s.label_efektif');
            })
            ->whereBetween('a.diambil_at', [Waktu::awalHari($start->toDateString()), Waktu::akhirHari($end->toDateString())])
            ->selectRaw('count(distinct a.id) AS total, coalesce(max(a.id), 0) AS max_id,
                max(a.updated_at) AS latest_article_update, max(s.updated_at) AS latest_analysis_update')
            ->first();

        return hash('sha256', json_encode([
            $start->toDateString(), $end->toDateString(), $row?->total, $row?->max_id,
            $row?->latest_article_update, $row?->latest_analysis_update,
        ]));
    }

    /** @param Collection<int, Artikel> $articles */
    private function consecutiveDays(Collection $articles): int
    {
        $dates = $articles->map(fn (Artikel $article) => Waktu::tanggalWita($article->diambil_at))->unique()->sort()->values();
        $best = $run = 0;
        $previous = null;

        foreach ($dates as $date) {
            $current = CarbonImmutable::parse($date);
            $run = $previous && $previous->addDay()->equalTo($current) ? $run + 1 : 1;
            $best = max($best, $run);
            $previous = $current;
        }

        return $best;
    }

    private function priorityScore(int $negative, int $sources, ?float $growth, int $consecutive): int
    {
        return ($negative >= 3 ? 2 : 0)
            + ($negative >= 7 ? 2 : 0)
            + ($sources >= 3 ? 2 : 0)
            + ($growth !== null && $growth >= 30 ? 2 : 0)
            + ($consecutive >= 2 ? 1 : 0)
            + ($consecutive >= 4 ? 1 : 0);
    }

    private function share(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count / $total * 100, 1);
    }

    private function startLog(string $period, string $model, int $count): ExecutiveAiLog
    {
        $log = ExecutiveAiLog::create([
            'task' => 'topics',
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
