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
}
