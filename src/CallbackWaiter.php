<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker;

use Closure;

/**
 * Adapter for anything that can wait, for example the LISTEN waiter of wwwision/dcb-eventstore-doctrine:
 *
 * new CallbackWaiter($eventStore->createWaiter($dedicatedConnection)->waitForNewEvents(...))
 */
final readonly class CallbackWaiter implements Waiter
{
    private Closure $callback;

    /**
     * @param callable(float): mixed $callback Receives the timeout in seconds, a return value is ignored
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback(...);
    }

    public function wait(float $timeoutSeconds): void
    {
        ($this->callback)($timeoutSeconds);
    }
}
