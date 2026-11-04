<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngineWorker\StopReason;
use Wwwision\SubscriptionEngineWorker\WorkerOptions;

final class WorkerLoopTest extends WorkerTestCase
{
    #[Test]
    public function bootingAndActiveSubscriptionsAreProcessedInBatchesUntilMaxIterations(): void
    {
        $this->appendEvents(10);
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $this->givenSubscription('booting', SubscriptionStatus::BOOTING);

        $reason = $this->worker($this->engine('active', 'booting'), WorkerOptions::create(limit: 4, maxIterations: 2, handleSignals: false))->run();

        self::assertSame(StopReason::MaxIterations, $reason);
        self::assertSame(['booting' => [1, 2, 3, 4], 'active' => [1, 2, 3, 4]], $this->handled);
    }

    #[Test]
    public function anIdleWorkerWaitsAndClaimsAgainAfterEveryWakeUp(): void
    {
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $this->waiter->onWait = function (float $timeout, int $call): void {
            if ($call === 2) {
                $this->appendEvents(3);
            }
        };

        $reason = $this->worker($this->engine('active'), WorkerOptions::create(maxIdleWait: 7.0, maxIterations: 1, handleSignals: false))->run();

        self::assertSame(StopReason::MaxIterations, $reason);
        self::assertSame([7.0, 7.0], $this->waiter->timeouts);
        self::assertSame(['active' => [1, 2, 3]], $this->handled);
    }

    #[Test]
    public function pendingEventsDrainTheWaiterAndContinueWithoutBlocking(): void
    {
        $this->appendEvents(6);
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);

        $this->worker($this->engine('active'), WorkerOptions::create(limit: 2, maxIterations: 3, handleSignals: false))->run();

        self::assertSame([0.0, 0.0], $this->waiter->timeouts);
        self::assertSame([], $this->sleeper->timeouts);
    }

    #[Test]
    public function allSubscriptionsLockedDrainsTheWaiterAndSleepsBriefly(): void
    {
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $this->store->_lock('active');
        $this->sleeper->onWait = function (float $timeout, int $call): void {
            if ($call === 3) {
                $this->store->_lock();
                $this->appendEvents(1);
            }
        };

        $this->worker($this->engine('active'), WorkerOptions::create(maxIterations: 1, handleSignals: false))->run();

        self::assertSame([0.0, 0.0, 0.0], $this->waiter->timeouts);
        self::assertCount(3, $this->sleeper->timeouts);
        foreach ($this->sleeper->timeouts as $timeout) {
            self::assertGreaterThanOrEqual(0.1, $timeout);
            self::assertLessThanOrEqual(0.5, $timeout);
        }
    }

    #[Test]
    public function maxRuntimeStopsTheWorkerAndShortensTheWait(): void
    {
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);

        $reason = $this->worker($this->engine('active'), WorkerOptions::create(maxIdleWait: 30.0, maxRuntime: 100.0, handleSignals: false))->run();

        self::assertSame(StopReason::MaxRuntime, $reason);
        $total = array_sum($this->waiter->timeouts);
        self::assertGreaterThanOrEqual(100.0, $total);
        self::assertLessThanOrEqual(110.0, $total, 'At most 10% of jitter');
        self::assertLessThan(30.0, end($this->waiter->timeouts), 'The last wait ends at the deadline');
    }

    #[Test]
    public function memoryLimitStopsTheWorkerAfterABatchButNotBeforeTheFirstOne(): void
    {
        $this->appendEvents(5);
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);

        $reason = $this->worker($this->engine('active'), WorkerOptions::create(limit: 2, memoryLimit: 1, handleSignals: false))->run();

        self::assertSame(StopReason::MemoryLimit, $reason);
        self::assertSame(['active' => [1, 2]], $this->handled);
        self::assertSame(2, $this->positionOf('active'));
    }
}
