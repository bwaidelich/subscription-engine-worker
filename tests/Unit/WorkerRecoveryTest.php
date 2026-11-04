<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit;

use Error;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngineWorker\StopReason;
use Wwwision\SubscriptionEngineWorker\WorkerFailed;
use Wwwision\SubscriptionEngineWorker\WorkerOptions;

final class WorkerRecoveryTest extends WorkerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
    }

    #[Test]
    public function aLostConnectionIsRecoveredWithGrowingDelays(): void
    {
        $this->appendEvents(2);
        $this->flakyStore->down = true;
        $recoveries = 0;
        $this->sleeper->onWait = function (float $timeout, int $call): void {
            if ($call === 7) {
                $this->flakyStore->down = false;
            }
        };
        $options = WorkerOptions::create(maxIterations: 1, recover: static function () use (&$recoveries): void {
            $recoveries++;
        }, handleSignals: false);

        $reason = $this->worker($this->engine('active'), $options)->run();

        self::assertSame(StopReason::MaxIterations, $reason);
        self::assertSame(['active' => [1, 2]], $this->handled);
        self::assertSame(7, $recoveries);
        foreach ([1, 2, 4, 8, 16, 30, 30] as $i => $base) {
            self::assertGreaterThanOrEqual($base * 0.8, $this->sleeper->timeouts[$i]);
            self::assertLessThanOrEqual($base * 1.2, $this->sleeper->timeouts[$i]);
        }
    }

    #[Test]
    public function theWorkerFailsAfterFiveMinutesWithoutASuccessfulCycle(): void
    {
        $this->flakyStore->down = true;
        $startedAt = (float) $this->clock->now()->format('U.u');

        try {
            $this->worker($this->engine('active'), WorkerOptions::create(handleSignals: false))->run();
            self::fail('Expected an exception');
        } catch (WorkerFailed $e) {
            self::assertSame('Connection lost', $e->getPrevious()?->getMessage());
        }
        self::assertGreaterThanOrEqual(300.0, (float) $this->clock->now()->format('U.u') - $startedAt);
        self::assertLessThan(340.0, (float) $this->clock->now()->format('U.u') - $startedAt);
    }

    #[Test]
    public function aSuccessfulCycleResetsTheFiveMinutes(): void
    {
        // down for more than three minutes, up for a single cycle, and so on. In total much longer than the five minutes
        $this->flakyStore->down = true;
        $downSince = (float) $this->clock->now()->format('U.u');
        $this->sleeper->onWait = function () use (&$downSince): void {
            if ((float) $this->clock->now()->format('U.u') - $downSince >= 200.0) {
                $this->flakyStore->down = false;
            }
        };
        $this->waiter->onWait = function () use (&$downSince): void {
            $this->flakyStore->down = true;
            $downSince = (float) $this->clock->now()->format('U.u');
        };

        $reason = $this->worker($this->engine('active'), WorkerOptions::create(maxIdleWait: 1.0, maxRuntime: 1000.0, handleSignals: false))->run();

        self::assertSame(StopReason::MaxRuntime, $reason);
    }

    #[Test]
    public function aFailingWaiterIsHandledLikeALostConnection(): void
    {
        $this->waiter->onWait = function (float $timeout, int $call): void {
            if ($call === 1) {
                throw new RuntimeException('Listen failed');
            }
            $this->appendEvents(1);
        };
        $recoveries = 0;
        $options = WorkerOptions::create(maxIterations: 1, recover: static function () use (&$recoveries): void {
            $recoveries++;
        }, handleSignals: false);

        $reason = $this->worker($this->engine('active'), $options)->run();

        self::assertSame(StopReason::MaxIterations, $reason);
        self::assertSame(1, $recoveries);
        self::assertCount(1, $this->sleeper->timeouts);
    }

    #[Test]
    public function aFailingRecoverCallbackDoesNotEndTheWorker(): void
    {
        $this->flakyStore->down = true;
        $this->sleeper->onWait = function (): void {
            $this->flakyStore->down = false;
        };
        $this->appendEvents(1);
        $options = WorkerOptions::create(maxIterations: 1, recover: static function (): void {
            throw new RuntimeException('Close failed');
        }, handleSignals: false);

        self::assertSame(StopReason::MaxIterations, $this->worker($this->engine('active'), $options)->run());
    }

    #[Test]
    public function aPhpErrorEscapesRightAway(): void
    {
        $this->waiter->onWait = static function (): void {
            throw new Error('A bug');
        };

        $this->expectExceptionMessage('A bug');
        $this->worker($this->engine('active'), WorkerOptions::create(handleSignals: false))->run();
    }
}
