<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;
use Wwwision\SubscriptionEngine\SubscriptionEngine;

final class Worker
{
    private int $iterations = 0;

    /**
     * @param SubscriptionEngine<object> $engine
     */
    public function __construct(
        private readonly SubscriptionEngine $engine,
        private readonly Waiter $waiter,
        private readonly WorkerOptions $options,
        LoggerInterface|null $logger = null,
        ClockInterface|null $clock = null,
        Waiter|null $sleeper = null,
        Randomizer|null $randomizer = null,
    ) {}

    public function run(): StopReason
    {
        while (true) {
            $result = $this->engine->bootNext($this->options->criteria, $this->options->limit);
            $this->iterations += $result->numberOfProcessedEvents > 0 ? 1 : 0;
            if ($this->iterations === $this->options->maxIterations) {
                return StopReason::MaxIterations;
            }
            $result = $this->engine->catchUpNextActive($this->options->criteria, $this->options->limit);
            $this->iterations += $result->numberOfProcessedEvents > 0 ? 1 : 0;
            if ($this->iterations === $this->options->maxIterations) {
                return StopReason::MaxIterations;
            }
        }
    }
}
