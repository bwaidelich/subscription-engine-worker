<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Wwwision\DCBEventStore\SequencedEvent\SequencedEvent;
use Wwwision\DCBEventStoreDoctrine\DoctrineEventStore;
use Wwwision\DCBEventStoreDoctrine\DoctrineEventStoreConfiguration;
use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\SubscriptionEngine;
use Wwwision\SubscriptionEngineDcbAdapter\DcbEventStoreAdapter;
use Wwwision\SubscriptionEngineDoctrine\DoctrineSubscriptionStore;

require __DIR__ . '/../vendor/autoload.php';

/**
 * The part that depends on the application: Connections, event store and subscribers.
 *
 * A projection that writes through $connection takes part in the transaction of the catch-up, and handles each event exactly once. Any other connection gets at-least-once
 *
 * @return array{connection: Connection, eventStore: DoctrineEventStore, engine: SubscriptionEngine<SequencedEvent>}
 */
return (static function (): array {
    $dsn = getenv('DATABASE_DSN');
    if (!is_string($dsn) || $dsn === '') {
        throw new RuntimeException('The environment variable DATABASE_DSN is not set');
    }
    $connection = DriverManager::getConnection((new DsnParser())->parse($dsn));
    $eventStore = new DoctrineEventStore(DoctrineEventStoreConfiguration::create($connection, 'events'));
    /** @var SubscriptionEngine<SequencedEvent> $engine */
    $engine = new SubscriptionEngine(
        new DcbEventStoreAdapter($eventStore),
        new DoctrineSubscriptionStore($connection, 'subscriptions'),
        Subscribers::fromArray([
            Subscriber::create(
                'some-projection',
                static function (SequencedEvent $event) use ($connection): void {
                    $connection->insert('some_projection', ['position' => $event->position->value]);
                },
                setup: static function () use ($connection): void {
                    $connection->executeStatement('CREATE TABLE IF NOT EXISTS some_projection (position BIGINT PRIMARY KEY)');
                },
                reset: static function () use ($connection): void {
                    $connection->executeStatement('TRUNCATE some_projection');
                },
            ),
        ]),
    );
    return ['connection' => $connection, 'eventStore' => $eventStore, 'engine' => $engine];
})();
