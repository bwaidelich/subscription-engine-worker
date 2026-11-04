<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit\Fixture;

use RuntimeException;
use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Store\SubscriptionStore;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\Subscriptions;

/**
 * Behaves like a database that is gone, as long as $down is set
 */
final class FlakyStore implements SubscriptionStore
{
    public bool $down = false;

    public function __construct(
        private readonly SubscriptionStore $store,
    ) {}

    public function setup(): void
    {
        $this->store->setup();
    }

    public function findByCriteria(SubscriptionCriteria $criteria): Subscriptions
    {
        return $this->store->findByCriteria($criteria);
    }

    public function findByCriteriaForUpdate(SubscriptionCriteria $criteria): Subscriptions
    {
        return $this->store->findByCriteriaForUpdate($criteria);
    }

    public function claimNext(SubscriptionCriteria $criteria): Subscription|null
    {
        return $this->store->claimNext($criteria);
    }

    public function add(Subscription $subscription): void
    {
        $this->store->add($subscription);
    }

    public function update(Subscription $subscription): void
    {
        $this->store->update($subscription);
    }

    public function beginTransaction(): void
    {
        if ($this->down) {
            throw new RuntimeException('Connection lost');
        }
        $this->store->beginTransaction();
    }

    public function commit(): void
    {
        $this->store->commit();
    }

    public function rollback(): void
    {
        $this->store->rollback();
    }
}
