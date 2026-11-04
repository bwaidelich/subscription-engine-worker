<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit\Fixture;

use Closure;
use Wwwision\SubscriptionEngineWorker\Waiter;

/**
 * Records the timeouts and lets the time pass on the fake clock, instead of blocking
 */
final class FakeWaiter implements Waiter
{
    /** @var list<float> */
    public array $timeouts = [];

    /**
     * @param Closure(float, int): void|null $onWait Called with the timeout and the number of the call, starting at 1
     */
    public function __construct(
        private readonly FakeClock $clock,
        public Closure|null $onWait = null,
    ) {}

    public function wait(float $timeoutSeconds): void
    {
        $this->timeouts[] = $timeoutSeconds;
        $this->clock->advance($timeoutSeconds);
        if ($this->onWait !== null) {
            ($this->onWait)($timeoutSeconds, count($this->timeouts));
        }
    }
}
