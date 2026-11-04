<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker;

use Exception;
use RuntimeException;

/**
 * The worker has given up, because no cycle succeeded for too long. An uncaught exception results in a non-zero exit code, so that the process manager takes over
 */
final class WorkerFailed extends RuntimeException
{
    public static function after(float $seconds, Exception $lastFailure): self
    {
        return new self(sprintf('No successful cycle for %d seconds, the last failure was: %s', $seconds, $lastFailure->getMessage()), 1793840002, $lastFailure);
    }
}
