<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker;

/**
 * Lets an idle worker wait until there might be new events
 */
interface Waiter
{
    /**
     * Blocks until there might be new events, or until the timeout expires. A signal may end the wait early
     *
     * The worker tries to claim a subscription after every call, however the wait ended
     *
     * @param float $timeoutSeconds Maximum time to wait, 0.0 must not block but should process notifications that have been received in the meantime
     * @throws \Exception if the wait failed, the worker recovers and backs off
     */
    public function wait(float $timeoutSeconds): void;
}
