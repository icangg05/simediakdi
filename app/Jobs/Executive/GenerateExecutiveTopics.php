<?php

namespace App\Jobs\Executive;

use App\Services\Executive\ExecutivePeriodService;
use App\Services\Executive\ExecutiveTopicService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateExecutiveTopics implements ShouldQueue
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

    public function handle(ExecutivePeriodService $periods, ExecutiveTopicService $topics): void
    {
        $resolved = $periods->resolve($periods->normalize($this->period));
        $topics->generate($resolved);

        GenerateExecutiveSummary::dispatch($this->period)->delay(now()->addMinute());
    }
}
