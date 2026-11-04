<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker;

/**
 * Sleeps for the whole timeout, or until a signal ends the sleep: The worker polls at the rate of its maxIdleWait option
 */
final readonly class SleepWaiter implements Waiter
{
    public function wait(float $timeoutSeconds): void
    {
        if ($timeoutSeconds <= 0.0) {
            return;
        }
        $seconds = (int) $timeoutSeconds;
        // the return value only tells whether a signal has ended the sleep, which is what we want
        @time_nanosleep($seconds, (int) (($timeoutSeconds - $seconds) * 1_000_000_000));
    }
}
