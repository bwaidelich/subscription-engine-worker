<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Wwwision\SubscriptionEngineWorker\CallbackWaiter;
use Wwwision\SubscriptionEngineWorker\Worker;
use Wwwision\SubscriptionEngineWorker\WorkerOptions;

['connection' => $connection, 'eventStore' => $eventStore, 'engine' => $engine] = require __DIR__ . '/bootstrap.php';

// LISTEN occupies its connection. It has to reach the primary directly, not through PgBouncer in transaction mode
$listenConnection = DriverManager::getConnection((new DsnParser())->parse((string) (getenv('LISTEN_DATABASE_DSN') ?: getenv('DATABASE_DSN'))));

$worker = new Worker($engine, new CallbackWaiter($eventStore->createWaiter($listenConnection)->waitForNewEvents(...)), WorkerOptions::create(
    limit: 500,
    maxRuntime: 3600.0,
    memoryLimit: 256 * 1024 * 1024,
    recover: $connection->close(...),
    onHeartbeat: static function (): void {
        touch('/tmp/worker-heartbeat');
    },
));
$worker->run();
