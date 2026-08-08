<?php

namespace App\Services\Executive;

use App\Models\Artikel;
use App\Support\Waktu;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ExecutiveAnalyticsService
{
    /** @return array<string, mixed> */
    public function metrics(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = DB::table('ringkasan_harian')
            ->whereNull('media_id')
            ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('coalesce(sum(jumlah_positif), 0)::int AS positif,
                coalesce(sum(jumlah_netral), 0)::int AS netral,
                coalesce(sum(jumlah_negatif), 0)::int AS negatif')
            ->first();

        $counts = array_map('intval', (array) $row);
        $total = array_sum($counts);
        $days = $start->diffInDays($end) + 1;

        return [
            'total_articles' => $total,
            'active_sources' => $this->activeSources($start, $end),
            'average_articles_per_day' => round($total / max(1, $days), 1),
            'sentiment' => [
                'positif' => $this->sentiment($counts['positif'] ?? 0, $total),
                'netral' => $this->sentiment($counts['netral'] ?? 0, $total),
                'negatif' => $this->sentiment($counts['negatif'] ?? 0, $total),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function comparison(array $current, array $previous): array
    {
        $sentiment = [];

        foreach (['positif', 'netral', 'negatif'] as $label) {
            $sentiment[$label] = [
                'percentage_points' => round(
                    $current['sentiment'][$label]['percentage'] - $previous['sentiment'][$label]['percentage'],
                    1,
                ),
                'count_difference' => $current['sentiment'][$label]['count'] - $previous['sentiment'][$label]['count'],
            ];
        }

        return [
            'total_articles' => $this->change($current['total_articles'], $previous['total_articles']),
            'active_sources' => $this->change($current['active_sources'], $previous['active_sources']),
            'average_articles_per_day' => $this->change(
                $current['average_articles_per_day'],
                $previous['average_articles_per_day'],
            ),
            'sentiment' => $sentiment,
        ];
    }

    /** @return list<array<string, int|string>> */
    public function sentimentTrend(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('ringkasan_harian')
            ->whereNull('media_id')
            ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
            ->orderBy('tanggal')
            ->get([
                'tanggal', 'jumlah_positif', 'jumlah_netral', 'jumlah_negatif', 'jumlah_perlu_review',
            ])
            ->keyBy(fn ($row) => (string) $row->tanggal);

        $result = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $row = $rows->get($key);
            $result[] = [
                'tanggal' => $key,
                'jumlah_positif' => (int) ($row->jumlah_positif ?? 0),
                'jumlah_netral' => (int) ($row->jumlah_netral ?? 0),
                'jumlah_negatif' => (int) ($row->jumlah_negatif ?? 0),
                'jumlah_perlu_review' => 0,
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function topSources(CarbonImmutable $start, CarbonImmutable $end, int $limit = 8): array
    {
        return DB::table('ringkasan_harian as r')
            ->join('media as m', 'm.id', '=', 'r.media_id')
            ->whereBetween('r.tanggal', [$start->toDateString(), $end->toDateString()])
            ->whereNotNull('r.media_id')
            ->groupBy('m.id', 'm.nama')
            ->havingRaw('sum(r.jumlah_positif + r.jumlah_netral + r.jumlah_negatif) > 0')
            ->orderByRaw('sum(r.jumlah_positif + r.jumlah_netral + r.jumlah_negatif) DESC')
            ->limit($limit)
            ->get([
                'm.id', 'm.nama',
                DB::raw('sum(r.jumlah_positif + r.jumlah_netral + r.jumlah_negatif)::int AS jumlah_artikel'),
                DB::raw('sum(r.jumlah_positif)::int AS jumlah_positif'),
                DB::raw('sum(r.jumlah_netral)::int AS jumlah_netral'),
                DB::raw('sum(r.jumlah_negatif)::int AS jumlah_negatif'),
            ])
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function representativeArticles(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $articles = Artikel::query()
            ->relevanBerlabel()
            ->with(['media:id,nama', 'analisisSentimen' => fn ($query) => $query->where('relevan', true)])
            ->whereBetween('diambil_at', [Waktu::awalHari($start->toDateString()), Waktu::akhirHari($end->toDateString())])
            ->orderByDesc('diambil_at')
            ->limit(80)
            ->get(['id', 'media_id', 'judul', 'url', 'ringkasan', 'diambil_at']);

        $map = fn (Artikel $article): array => [
            'id' => $article->id,
            'judul' => $article->judul,
            'url' => $article->url,
            'ringkasan' => $article->ringkasan,
            'media' => $article->media?->nama,
            'diambil_at' => $article->diambil_at,
            'label' => $article->analisisSentimen->first()?->label_efektif?->value,
        ];

        return [
            'perlu_diperhatikan' => $articles->filter(fn ($article) => $article->analisisSentimen->first()?->label_efektif?->value === 'negatif')->take(4)->map($map)->values()->all(),
            'positif_utama' => $articles->filter(fn ($article) => $article->analisisSentimen->first()?->label_efektif?->value === 'positif')->take(4)->map($map)->values()->all(),
            'terbaru' => $articles->take(6)->map($map)->values()->all(),
        ];
    }

    public function updatedAt(CarbonImmutable $start, CarbonImmutable $end): ?string
    {
        $value = DB::table('ringkasan_harian')
            ->whereNull('media_id')
            ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
            ->max('dihitung_at');

        return $value ? CarbonImmutable::parse($value)->setTimezone(Waktu::ZONA)->toIso8601String() : null;
    }

    private function activeSources(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return DB::query()->fromSub(
            DB::table('ringkasan_harian')
                ->select('media_id')
                ->whereNotNull('media_id')
                ->whereBetween('tanggal', [$start->toDateString(), $end->toDateString()])
                ->groupBy('media_id')
                ->havingRaw('sum(jumlah_positif + jumlah_netral + jumlah_negatif) > 0'),
            'active_media',
        )->count();
    }

    /** @return array{count: int, percentage: float} */
    private function sentiment(int $count, int $total): array
    {
        return ['count' => $count, 'percentage' => $total === 0 ? 0.0 : round($count / $total * 100, 1)];
    }

    /** @return array{current: float|int, previous: float|int, difference: float|int, percentage: ?float} */
    private function change(float|int $current, float|int $previous): array
    {
        return [
            'current' => $current,
            'previous' => $previous,
            'difference' => round($current - $previous, 1),
            'percentage' => $previous == 0 ? null : round(($current - $previous) / $previous * 100, 1),
        ];
    }
}
