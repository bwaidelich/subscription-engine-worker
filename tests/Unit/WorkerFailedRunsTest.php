<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngineWorker\WorkerOptions;

/**
 * A run that fails after the claim rolls back and keeps the status of the subscription. A failing update of the store causes that in the same way as an SQL error of a handler on PostgreSQL
 */
final class WorkerFailedRunsTest extends WorkerTestCase
{
    private int $attemptsOfFailing = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appendEvents(3);
        $this->givenSubscription('failing', SubscriptionStatus::ACTIVE);
        $this->handlers['failing'] = function ($event): void {
            if ($event->position->value === 1) {
                $this->attemptsOfFailing++;
            }
        };
        $this->store->_failOnUpdateOf('failing');
    }

    #[Test]
    public function healthySubscriptionsContinueWhileAFailingOneBacksOff(): void
    {
        $this->givenSubscription('healthy', SubscriptionStatus::ACTIVE);
        // every wake-up comes with a new event and barely takes any time, as with constant appends
        $this->waiter->usesUpTimeout = false;
        $this->waiter->onWait = function (): void {
            $this->clock->advance(0.01);
            $this->appendEvents(1);
        };

        $this->worker($this->engine('failing', 'healthy'), WorkerOptions::create(maxRuntime: 0.5, handleSignals: false))->run();

        self::assertSame(1, $this->attemptsOfFailing, 'A wake-up must not retry it before the delay has expired');
        self::assertSame(0, $this->positionOf('failing'));
        self::assertGreaterThan(10, $this->positionOf('healthy'));
    }

    #[Test]
    public function theDelayGrowsExponentiallyAndUpToTheMaximum(): void
    {
        $retriedAt = [];
        $this->handlers['failing'] = function ($event) use (&$retriedAt): void {
            if ($event->position->value === 1) {
                $retriedAt[] = (float) $this->clock->now()->format('U.u');
            }
        };

        $this->worker($this->engine('failing'), WorkerOptions::create(maxIdleWait: 1000.0, maxRuntime: 400.0, handleSignals: false))->run();

        $delays = [];
        foreach ($retriedAt as $i => $time) {
            if ($i > 0) {
                $delays[] = $time - $retriedAt[$i - 1];
            }
        }
        $expected = [1, 2, 4, 8, 16, 32, 60, 60];
        foreach ($expected as $i => $base) {
            self::assertGreaterThanOrEqual($base * 0.8 - 0.001, $delays[$i], json_encode($delays, JSON_THROW_ON_ERROR));
            self::assertLessThanOrEqual($base * 1.2 + 0.001, $delays[$i], json_encode($delays, JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function aRetryHappensAsSoonAsTheDelayExpiredEvenIfOtherSubscriptionsAreIdle(): void
    {
        $this->givenSubscription('idle', SubscriptionStatus::ACTIVE, 3);

        $this->worker($this->engine('failing', 'idle'), WorkerOptions::create(maxIdleWait: 1000.0, maxRuntime: 3.0, handleSignals: false))->run();

        self::assertGreaterThanOrEqual(2, $this->attemptsOfFailing, 'The engine has set it aside, so the first cycle after the delay only clears that');
    }

    #[Test]
    public function aSuccessfulRunClearsTheBackoff(): void
    {
        $this->waiter->onWait = function (float $timeout, int $call): void {
            if ($call === 3) {
                $this->store->_failOnUpdateOf();
            }
        };

        $this->worker($this->engine('failing'), WorkerOptions::create(maxIdleWait: 7.0, maxRuntime: 100.0, handleSignals: false))->run();

        self::assertSame(3, $this->positionOf('failing'));
        self::assertGreaterThan(10, count(array_filter($this->waiter->timeouts, static fn(float $timeout) => $timeout === 7.0)), 'Back to the regular wait');
    }

    #[Test]
    public function recoverIsCalledAfterAFailedRun(): void
    {
        $recoveries = 0;
        $options = WorkerOptions::create(maxRuntime: 0.5, recover: static function () use (&$recoveries): void {
            $recoveries++;
        }, handleSignals: false);

        $this->worker($this->engine('failing'), $options)->run();

        self::assertSame(1, $recoveries);
    }
}
