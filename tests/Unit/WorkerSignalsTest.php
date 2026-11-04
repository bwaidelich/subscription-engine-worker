<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngineWorker\StopReason;
use Wwwision\SubscriptionEngineWorker\WorkerOptions;

#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class WorkerSignalsTest extends WorkerTestCase
{
    /**
     * @return iterable<array{int}>
     */
    public static function stopSignals(): iterable
    {
        yield 'SIGTERM' => [SIGTERM];
        yield 'SIGINT' => [SIGINT];
    }

    #[Test]
    #[DataProvider('stopSignals')]
    public function aSignalDuringAWaitStopsTheWorker(int $signal): void
    {
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $this->waiter->onWait = static function () use ($signal): void {
            posix_kill(getmypid(), $signal);
        };

        $reason = $this->worker($this->engine('active'), WorkerOptions::create())->run();

        self::assertSame(StopReason::Signal, $reason);
        self::assertCount(1, $this->waiter->timeouts);
    }

    #[Test]
    public function aSignalDuringABatchFinishesAndPersistsThatBatchOnly(): void
    {
        $this->appendEvents(10);
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $this->handlers['active'] = static function (): void {
            posix_kill(getmypid(), SIGTERM);
        };

        $reason = $this->worker($this->engine('active'), WorkerOptions::create(limit: 3))->run();

        self::assertSame(StopReason::Signal, $reason);
        self::assertSame(['active' => [1, 2, 3]], $this->handled);
        self::assertSame(3, $this->positionOf('active'));
    }

    #[Test]
    public function previousSignalHandlersAndTheAsyncModeAreRestoredAfterTheRun(): void
    {
        $previousHandler = static function (): void {};
        pcntl_signal(SIGTERM, $previousHandler);
        pcntl_async_signals(false);
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $this->waiter->onWait = static function (): void {
            posix_kill(getmypid(), SIGTERM);
        };
        try {
            $this->worker($this->engine('active'), WorkerOptions::create())->run();

            self::assertSame($previousHandler, pcntl_signal_get_handler(SIGTERM));
            self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGINT));
            self::assertFalse(pcntl_async_signals());
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
        }
    }

    #[Test]
    public function handlersAreNotInstalledIfDisabled(): void
    {
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $handlerDuringRun = null;
        $this->waiter->onWait = static function () use (&$handlerDuringRun): void {
            $handlerDuringRun = pcntl_signal_get_handler(SIGTERM);
        };

        $this->worker($this->engine('active'), WorkerOptions::create(maxRuntime: 1.0, handleSignals: false))->run();

        self::assertSame(SIG_DFL, $handlerDuringRun);
    }
}
