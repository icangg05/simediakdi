<?php

namespace App\Services\Executive;

use App\Support\Waktu;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class ExecutivePeriodService
{
    /** @var array<string, int> */
    private const DAYS = ['today' => 1, '7d' => 7, '30d' => 30, '90d' => 90];

    /**
     * @return array{
     *   type: string, label: string, days: int,
     *   current: array{start: CarbonImmutable, end: CarbonImmutable},
     *   previous: array{start: CarbonImmutable, end: CarbonImmutable}
     * }
     */
    public function resolve(string $period): array
    {
        if (! array_key_exists($period, self::DAYS)) {
            throw new InvalidArgumentException('Periode dashboard tidak valid.');
        }

        $days = self::DAYS[$period];
        $end = CarbonImmutable::parse(Waktu::tanggalWita(now()));
        $start = $end->subDays($days - 1);

        return [
            'type' => $period,
            'label' => match ($period) {
                'today' => 'Hari Ini',
                '7d' => '7 Hari',
                '30d' => '30 Hari',
                '90d' => '3 Bulan',
            },
            'days' => $days,
            'current' => ['start' => $start, 'end' => $end],
            'previous' => [
                'start' => $start->subDays($days),
                'end' => $start->subDay(),
            ],
        ];
    }

    public function normalize(?string $period): string
    {
        return array_key_exists((string) $period, self::DAYS) ? (string) $period : '7d';
    }

    public function topicLimit(string $period): int
    {
        return $period === 'today' ? 5 : ($period === '7d' ? 7 : 8);
    }
}
