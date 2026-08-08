<?php

namespace App\Jobs\Executive;

use App\Services\Executive\ExecutivePeriodService;
use App\Services\Executive\ExecutiveSummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateExecutiveSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 240;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public string $period = '7d')
    {
        $this->onQueue('gemini');
    }

    public function handle(ExecutivePeriodService $periods, ExecutiveSummaryService $summaries): void
    {
        $summaries->generate($periods->resolve($periods->normalize($this->period)));
    }
}
