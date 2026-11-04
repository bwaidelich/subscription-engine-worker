<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Wwwision\SubscriptionEngineWorker\CallbackWaiter;
use Wwwision\SubscriptionEngineWorker\SleepWaiter;

final class WaitersTest extends TestCase
{
    #[Test]
    public function callbackWaiterPassesTheTimeoutAndIgnoresTheResult(): void
    {
        $received = [];
        $waiter = new CallbackWaiter(static function (float $timeout) use (&$received): bool {
            $received[] = $timeout;
            return false;
        });

        $waiter->wait(1.5);

        self::assertSame([1.5], $received);
    }

    #[Test]
    public function sleepWaiterSleepsForTheTimeout(): void
    {
        $start = hrtime(true);
        (new SleepWaiter())->wait(0.05);
        self::assertGreaterThanOrEqual(50_000_000, hrtime(true) - $start);
    }

    #[Test]
    public function sleepWaiterDoesNotBlockWithoutATimeout(): void
    {
        $start = hrtime(true);
        (new SleepWaiter())->wait(0.0);
        self::assertLessThan(10_000_000, hrtime(true) - $start);
    }

    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function aSignalEndsTheSleepOfSleepWaiterEarly(): void
    {
        $previousAsync = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {});
        pcntl_alarm(1);
        try {
            $start = hrtime(true);
            (new SleepWaiter())->wait(5.0);
            self::assertLessThan(3_000_000_000, hrtime(true) - $start);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_async_signals($previousAsync);
        }
    }
}
