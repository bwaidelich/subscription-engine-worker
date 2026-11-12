<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Wwwision\SubscriptionEngineWorker\CallbackWaiter;
use Wwwision\SubscriptionEngineWorker\Worker;
use Wwwision\SubscriptionEngineWorker\WorkerOptions;

['connection' => $connection, 'eventStore' => $eventStore, 'engine' => $engine] = require __DIR__ . '/bootstrap.php';

// The waiter checks the head of the event store every second, on a connection of its own
$waiterConnection = DriverManager::getConnection((new DsnParser())->parse((string) getenv('DATABASE_DSN')));

$worker = new Worker($engine, new CallbackWaiter($eventStore->createWaiter($waiterConnection)->waitForNewEvents(...)), WorkerOptions::create(
    limit: 500,
    maxRuntime: 3600.0,
    memoryLimit: 256 * 1024 * 1024,
    recover: $connection->close(...),
    onHeartbeat: static function (): void {
        touch('/tmp/worker-heartbeat');
    },
));
$worker->run();
