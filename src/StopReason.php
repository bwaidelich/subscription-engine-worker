<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker;

/**
 * Why {@see Worker::run()} has returned
 */
enum StopReason: string
{
    case Signal = 'signal';
    case MaxRuntime = 'max runtime';
    case MaxIterations = 'max iterations';
    case MemoryLimit = 'memory limit';
}
