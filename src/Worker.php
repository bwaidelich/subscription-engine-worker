<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;
use Wwwision\SubscriptionEngine\Engine\ProcessedResult;
use Wwwision\SubscriptionEngine\SubscriptionEngine;

/**
 * Claims free subscriptions, catches them up in bounded batches and waits when there is nothing to do. See docs/decisions.md
 */
final class Worker
{
    private const LOCKED_SLEEP_MIN_SECONDS = 0.1;
    private const LOCKED_SLEEP_MAX_SECONDS = 0.5;
    private const MAX_RUNTIME_JITTER = 0.1;

    private readonly ClockInterface $clock;
    private readonly Waiter $sleeper;
    private readonly Randomizer $randomizer;

    private int $iterations = 0;
    private float|null $deadline = null;

    /**
     * @param SubscriptionEngine<object> $engine
     * @param ClockInterface|null $clock For tests
     * @param Waiter|null $sleeper For tests: Used for sleeps that must not be ended by new events
     * @param Randomizer|null $randomizer For tests
     */
    public function __construct(
        private readonly SubscriptionEngine $engine,
        private readonly Waiter $waiter,
        private readonly WorkerOptions $options,
        LoggerInterface|null $logger = null,
        ClockInterface|null $clock = null,
        Waiter|null $sleeper = null,
        Randomizer|null $randomizer = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->sleeper = $sleeper ?? new SleepWaiter();
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    public function run(): StopReason
    {
        $this->iterations = 0;
        $this->deadline = $this->options->maxRuntime !== null
            ? $this->now() + $this->options->maxRuntime * (1 + $this->randomizer->getFloat(0.0, self::MAX_RUNTIME_JITTER))
            : null;
        while (true) {
            $stopReason = $this->stopReason() ?? $this->cycle();
            if ($stopReason !== null) {
                return $stopReason;
            }
        }
    }

    private function cycle(): StopReason|null
    {
        $hasPendingEvents = false;
        $allLocked = false;
        foreach ([$this->engine->bootNext(...), $this->engine->catchUpNextActive(...)] as $catchUp) {
            $result = $catchUp($this->options->criteria, $this->options->limit);
            $stopReason = $this->stopReasonAfterBatch($result);
            if ($stopReason !== null) {
                return $stopReason;
            }
            $hasPendingEvents = $hasPendingEvents || $result->hasPendingEvents;
            $allLocked = $allLocked || $result->allSubscriptionsLocked;
        }
        if ($hasPendingEvents) {
            $this->waiter->wait(0.0);
            return null;
        }
        if ($allLocked) {
            $this->waiter->wait(0.0);
            $this->sleeper->wait($this->untilDeadline($this->randomizer->getFloat(self::LOCKED_SLEEP_MIN_SECONDS, self::LOCKED_SLEEP_MAX_SECONDS)));
            return null;
        }
        $this->waiter->wait($this->untilDeadline($this->options->maxIdleWait));
        return null;
    }

    private function stopReasonAfterBatch(ProcessedResult $result): StopReason|null
    {
        if ($result->numberOfProcessedEvents > 0) {
            $this->iterations++;
            if ($this->iterations === $this->options->maxIterations) {
                return StopReason::MaxIterations;
            }
            if ($this->options->memoryLimit !== null && memory_get_usage(true) >= $this->options->memoryLimit) {
                return StopReason::MemoryLimit;
            }
        }
        return $this->stopReason();
    }

    private function stopReason(): StopReason|null
    {
        if ($this->deadline !== null && $this->now() >= $this->deadline) {
            return StopReason::MaxRuntime;
        }
        return null;
    }

    private function untilDeadline(float $seconds): float
    {
        return $this->deadline !== null ? max(0.0, min($seconds, $this->deadline - $this->now())) : $seconds;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
