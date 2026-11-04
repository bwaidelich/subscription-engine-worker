<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit\Fixture;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FakeClock implements ClockInterface
{
    private float $now = 1_000_000.0;

    public function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $this->now)) ?: throw new \RuntimeException('Invalid time');
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
