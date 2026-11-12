<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Wwwision\DCBEventStore\SequencedEvent\SequencedEvent;
use Wwwision\DCBEventStoreDoctrine\DoctrineEventStore;
use Wwwision\DCBEventStoreDoctrine\DoctrineEventStoreConfiguration;
use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\SubscriptionEngine;
use Wwwision\SubscriptionEngineDcbAdapter\DcbEventStoreAdapter;
use Wwwision\SubscriptionEngineDoctrine\DoctrineSubscriptionStore;
use Wwwision\SubscriptionEngineWorker\CallbackWaiter;
use Wwwision\SubscriptionEngineWorker\SleepWaiter;
use Wwwision\SubscriptionEngineWorker\Worker;
use Wwwision\SubscriptionEngineWorker\WorkerOptions;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * One worker process of {@see WorkersIntegrationTest}, the configuration is passed as JSON argument
 */

$config = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$dsn = (new DsnParser())->parse((string) getenv('TEST_DATABASE_DSN'));
$connection = DriverManager::getConnection($dsn);
$eventStore = new DoctrineEventStore(DoctrineEventStoreConfiguration::create($connection, 'events'));

$handlerCalls = 0;
$subscribers = [];
foreach ($config['subscriptions'] as $id) {
    $subscribers[] = Subscriber::create($id, static function (SequencedEvent $event) use ($id, $connection, $config, &$handlerCalls): void {
        $handlerCalls++;
        if (($config['killAtHandlerCall'] ?? null) === $handlerCalls) {
            posix_kill(getmypid(), SIGKILL);
        }
        $connection->insert('projection', ['subscription' => $id, 'position' => $event->position->value, 'worker' => $config['name']]);
        usleep($config['sleepUs']);
    });
}
$engine = new SubscriptionEngine(new DcbEventStoreAdapter($eventStore), new DoctrineSubscriptionStore($connection, 'subscriptions'), Subscribers::fromArray($subscribers));

$waiter = $config['waiter'] === 'eventStore'
    ? new CallbackWaiter($eventStore->createWaiter(DriverManager::getConnection($dsn), 0.1)->waitForNewEvents(...))
    : new SleepWaiter();

$logger = new class extends AbstractLogger {
    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (in_array($level, [LogLevel::WARNING, LogLevel::ERROR], true)) {
            fwrite(STDERR, $level . ': ' . $message . "\n");
        }
    }
};

$stopReason = (new Worker($engine, $waiter, WorkerOptions::create(limit: $config['limit'], maxIdleWait: $config['maxIdleWait'], recover: $connection->close(...)), $logger))->run();
fwrite(STDOUT, $stopReason->value . "\n");
