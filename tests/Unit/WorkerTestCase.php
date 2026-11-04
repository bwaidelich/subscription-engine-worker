<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngine\SubscriptionEngine;
use Wwwision\SubscriptionEngine\Tests\Mocks\InMemoryEventStoreAdapter;
use Wwwision\SubscriptionEngine\Tests\Mocks\InMemorySubscriptionStore;
use Wwwision\SubscriptionEngine\Tests\Mocks\MockEvent;
use Wwwision\SubscriptionEngineWorker\Tests\Unit\Fixture\FakeClock;
use Wwwision\SubscriptionEngineWorker\Tests\Unit\Fixture\FakeWaiter;
use Wwwision\SubscriptionEngineWorker\Worker;
use Wwwision\SubscriptionEngineWorker\WorkerOptions;

/**
 * Runs the real engine on in-memory stores. Only the time is fake, so that no test has to sleep
 */
abstract class WorkerTestCase extends TestCase
{
    protected InMemoryEventStoreAdapter $eventStore;
    protected InMemorySubscriptionStore $store;
    protected FakeClock $clock;
    protected FakeWaiter $waiter;
    protected FakeWaiter $sleeper;

    /** @var array<string, list<int>> */
    protected array $handled = [];

    /** @var array<string, callable(MockEvent): void> */
    protected array $handlers = [];

    protected function setUp(): void
    {
        $this->eventStore = new InMemoryEventStoreAdapter();
        $this->store = new InMemorySubscriptionStore();
        $this->clock = new FakeClock();
        $this->waiter = new FakeWaiter($this->clock);
        $this->sleeper = new FakeWaiter($this->clock);
    }

    protected function appendEvents(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->eventStore->append(null);
        }
    }

    protected function givenSubscription(string $id, SubscriptionStatus $status, int $position = 0): void
    {
        $existing = iterator_to_array($this->store->findByCriteria(\Wwwision\SubscriptionEngine\Store\SubscriptionCriteria::noConstraints()), false);
        $this->store->_setSubscriptions(...[...$existing, Subscription::create($id, RunMode::FROM_BEGINNING, $status)->with(position: Position::fromInteger($position))]);
    }

    protected function engine(string ...$subscriptionIds): SubscriptionEngine
    {
        $subscribers = array_map(fn(string $id) => Subscriber::create($id, function (MockEvent $event) use ($id): void {
            if (isset($this->handlers[$id])) {
                ($this->handlers[$id])($event);
            }
            $this->handled[$id][] = $event->position->value;
        }), $subscriptionIds);
        return new SubscriptionEngine($this->eventStore, $this->store, Subscribers::fromArray($subscribers));
    }

    protected function worker(SubscriptionEngine $engine, WorkerOptions $options, LoggerInterface|null $logger = null): Worker
    {
        return new Worker($engine, $this->waiter, $options, $logger, $this->clock, $this->sleeper, new Randomizer(new Mt19937(1)));
    }

    protected function positionOf(string $id): int
    {
        return iterator_to_array($this->store->findByCriteria(\Wwwision\SubscriptionEngine\Store\SubscriptionCriteria::noConstraints()))[$id]->position->value;
    }
}
