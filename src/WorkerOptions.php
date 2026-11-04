<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker;

use Closure;
use Wwwision\SubscriptionEngine\Engine\SubscriptionEngineCriteria;

final readonly class WorkerOptions
{
    /**
     * @param Closure(): void|null $recover
     * @param Closure(): void|null $onHeartbeat
     */
    private function __construct(
        public int $limit,
        public SubscriptionEngineCriteria|null $criteria,
        public float $maxIdleWait,
        public float|null $maxRuntime,
        public int|null $maxIterations,
        public int|null $memoryLimit,
        public Closure|null $recover,
        public Closure|null $onHeartbeat,
        public bool $handleSignals,
    ) {}

    /**
     * @param int $limit Maximum number of events per batch, see {@see \Wwwision\SubscriptionEngine\SubscriptionEngine::catchUpNextActive()}
     * @param SubscriptionEngineCriteria|null $criteria Restricts this worker to certain subscriptions, for example to dedicate a pool to them
     * @param float $maxIdleWait Seconds an idle worker waits at most before it tries to claim a subscription again
     * @param float|null $maxRuntime Seconds after which the worker stops, plus up to 10% of jitter
     * @param int|null $maxIterations Number of catch-up calls that processed events after which the worker stops
     * @param int|null $memoryLimit Bytes of allocated memory after which the worker stops, checked after each batch
     * @param callable(): void|null $recover Called after a failure, for example to close a connection so that it reconnects: $connection->close(...)
     * @param callable(): void|null $onHeartbeat Called once per cycle, for example to touch a file for a liveness probe
     * @param bool $handleSignals Whether SIGTERM and SIGINT stop the worker gracefully. This requires ext-pcntl
     */
    public static function create(
        int $limit = 500,
        SubscriptionEngineCriteria|null $criteria = null,
        float $maxIdleWait = 30.0,
        float|null $maxRuntime = null,
        int|null $maxIterations = null,
        int|null $memoryLimit = null,
        callable|null $recover = null,
        callable|null $onHeartbeat = null,
        bool $handleSignals = true,
    ): self {
        return new self(
            $limit,
            $criteria,
            $maxIdleWait,
            $maxRuntime,
            $maxIterations,
            $memoryLimit,
            $recover !== null ? $recover(...) : null,
            $onHeartbeat !== null ? $onHeartbeat(...) : null,
            $handleSignals,
        );
    }
}
