<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Wwwision\DCBEventStore\Event\Event;
use Wwwision\DCBEventStore\Event\Events;
use Wwwision\DCBEventStoreDoctrine\DoctrineEventStore;
use Wwwision\DCBEventStoreDoctrine\DoctrineEventStoreConfiguration;
use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngine\SubscriptionEngine;
use Wwwision\SubscriptionEngineDcbAdapter\DcbEventStoreAdapter;
use Wwwision\SubscriptionEngineDoctrine\DoctrineSubscriptionStore;

/**
 * Real worker processes on one PostgreSQL database
 *
 * Requires the environment variable TEST_DATABASE_DSN, for example: pdo-pgsql://postgres:test@127.0.0.1:5432/test
 *
 * "Exactly once" is asserted on committed rows, handler calls legitimately repeat after a rollback
 */
#[RequiresPhpExtension('pcntl')]
#[RequiresPhpExtension('posix')]
final class WorkersIntegrationTest extends TestCase
{
    private const SUBSCRIPTIONS = ['s1', 's2', 's3'];

    private Connection $connection;
    private string $runDirectory;

    /** @var array<string, array{process: resource, stdout: string, stderr: string, exitCode: int|null, signal: int|null}> */
    private array $workers = [];

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DATABASE_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DATABASE_DSN is not set');
        }
        $this->connection = DriverManager::getConnection((new DsnParser())->parse($dsn));
        foreach (['events', 'subscriptions', 'projection'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $this->connection->executeStatement('CREATE TABLE projection (sequence BIGSERIAL, subscription VARCHAR(50) NOT NULL, position BIGINT NOT NULL, worker VARCHAR(50) NOT NULL, UNIQUE (subscription, position))');
        $this->eventStore()->setup();
        $this->runDirectory = sys_get_temp_dir() . '/' . uniqid('subscription-engine-worker-', true);
        mkdir($this->runDirectory);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $name => $worker) {
            if ($worker['exitCode'] === null && $worker['signal'] === null) {
                proc_terminate($worker['process'], SIGKILL);
            }
            proc_close($worker['process']);
        }
        if (isset($this->runDirectory)) {
            array_map(unlink(...), glob($this->runDirectory . '/*') ?: []);
            rmdir($this->runDirectory);
        }
    }

    /**
     * @return iterable<array{string}>
     */
    public static function waiters(): iterable
    {
        yield 'sleep-poll' => ['sleep'];
        yield 'event store waiter' => ['eventStore'];
    }

    #[Test]
    #[DataProvider('waiters')]
    public function twoWorkersHandleEveryEventExactlyOnceAndStopGracefully(string $waiter): void
    {
        $this->setupSubscriptions();
        $this->append(200);

        $this->startWorker('w1', ['waiter' => $waiter]);
        $this->startWorker('w2', ['waiter' => $waiter]);
        // a concurrent writer, including appends while the workers might be idle
        for ($i = 0; $i < 20; $i++) {
            $this->append(10);
            usleep(20_000);
        }
        $this->waitForRows(400 * count(self::SUBSCRIPTIONS));
        $this->stopWorker('w1');
        $this->stopWorker('w2');

        $this->assertEveryEventHandledExactlyOnce(400);
        self::assertSame(0, $this->workers['w1']['exitCode'], $this->workers['w1']['stderr']);
        self::assertSame(0, $this->workers['w2']['exitCode'], $this->workers['w2']['stderr']);
        self::assertStringContainsString('signal', $this->workers['w1']['stdout']);
        self::assertSame(['w1', 'w2'], $this->connection->fetchFirstColumn('SELECT DISTINCT worker FROM projection ORDER BY worker'), 'Both workers should have done some work');
        $this->assertAllSubscriptionsActiveAt(400);
    }

    #[Test]
    public function aWorkerWithTheEventStoreWaiterWakesUpLongBeforeItsTimeout(): void
    {
        $this->setupSubscriptions();
        $this->startWorker('w1', ['waiter' => 'eventStore', 'maxIdleWait' => 30.0]);
        $this->waitForActive();
        // the first waits of the waiter return immediately, let it block
        usleep(500_000);

        $start = microtime(true);
        $this->append(1);
        $this->waitForRows(count(self::SUBSCRIPTIONS), 10.0);

        self::assertLessThan(5.0, microtime(true) - $start);
    }

    #[Test]
    public function aWorkerKilledInTheMiddleOfABatchLeavesNothingBehindAndAnotherOneTakesOver(): void
    {
        $this->setupSubscriptions();
        $this->append(300);

        $this->startWorker('killed', ['waiter' => 'sleep', 'limit' => 100, 'killAtHandlerCall' => 50]);
        $this->waitForExit('killed');
        self::assertSame(SIGKILL, $this->workers['killed']['signal']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projection'), 'Its batch has to be rolled back');

        $this->startWorker('successor', ['waiter' => 'sleep']);
        $this->waitForRows(300 * count(self::SUBSCRIPTIONS));
        $this->stopWorker('successor');

        $this->assertEveryEventHandledExactlyOnce(300);
        self::assertSame(0, $this->workers['successor']['exitCode'], $this->workers['successor']['stderr']);
    }

    #[Test]
    #[DataProvider('waiters')]
    public function workersRecoverIfTheirConnectionsAreTerminatedInTheMiddleOfABatch(string $waiter): void
    {
        $this->setupSubscriptions();
        $this->append(300);
        $this->startWorker('w1', ['waiter' => $waiter, 'sleepUs' => 2000]);
        $this->startWorker('w2', ['waiter' => $waiter, 'sleepUs' => 2000]);

        $this->waitForRows(50);
        $this->terminateOtherConnections();
        $this->waitForRows(300 * count(self::SUBSCRIPTIONS));
        $this->stopWorker('w1');
        $this->stopWorker('w2');

        $this->assertEveryEventHandledExactlyOnce(300);
        self::assertSame(0, $this->workers['w1']['exitCode'], $this->workers['w1']['stderr']);
        self::assertSame(0, $this->workers['w2']['exitCode'], $this->workers['w2']['stderr']);
    }

    #[Test]
    #[DataProvider('waiters')]
    public function idleWorkersRecoverIfTheirConnectionsAreTerminated(string $waiter): void
    {
        $this->setupSubscriptions();
        $this->append(10);
        $this->startWorker('w1', ['waiter' => $waiter]);
        $this->startWorker('w2', ['waiter' => $waiter]);
        $this->waitForRows(10 * count(self::SUBSCRIPTIONS));
        usleep(500_000);

        $this->terminateOtherConnections();
        usleep(100_000);
        $this->append(10);
        $this->waitForRows(20 * count(self::SUBSCRIPTIONS));
        $this->stopWorker('w1');
        $this->stopWorker('w2');

        $this->assertEveryEventHandledExactlyOnce(20);
        self::assertSame(0, $this->workers['w1']['exitCode'], $this->workers['w1']['stderr']);
        self::assertSame(0, $this->workers['w2']['exitCode'], $this->workers['w2']['stderr']);
    }

    #[Test]
    public function theExampleScriptsWork(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS some_projection');
        $this->connection->executeStatement('DROP TABLE IF EXISTS events');
        $environment = ['DATABASE_DSN' => (string) getenv('TEST_DATABASE_DSN')] + getenv();
        $root = __DIR__ . '/../../examples/';
        exec(sprintf('DATABASE_DSN=%s %s %s 2>&1', escapeshellarg($environment['DATABASE_DSN']), escapeshellarg(PHP_BINARY), escapeshellarg($root . 'setup.php')), $output, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $output));
        $this->append(3);

        $stderr = $this->runDirectory . '/example.err';
        $process = proc_open([PHP_BINARY, $root . 'worker.php'], [1 => ['file', $stderr, 'a'], 2 => ['file', $stderr, 'a']], $pipes, null, $environment);
        self::assertIsResource($process);
        try {
            $deadline = microtime(true) + 20.0;
            while ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM some_projection') < 3) {
                if (microtime(true) > $deadline) {
                    self::fail('The example did not process the events: ' . file_get_contents($stderr));
                }
                usleep(10_000);
            }
            $output = [];
            exec(sprintf('DATABASE_DSN=%s %s %s 2>&1', escapeshellarg($environment['DATABASE_DSN']), escapeshellarg(PHP_BINARY), escapeshellarg($root . 'append-event.php')), $output, $exitCode);
            self::assertSame(0, $exitCode, implode("\n", $output));
            $deadline = microtime(true) + 20.0;
            while ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM some_projection') < 4) {
                if (microtime(true) > $deadline) {
                    self::fail('The example did not process the appended event: ' . file_get_contents($stderr));
                }
                usleep(10_000);
            }
            $output = [];
            exec(sprintf('DATABASE_DSN=%s %s %s some-projection 2>&1', escapeshellarg($environment['DATABASE_DSN']), escapeshellarg(PHP_BINARY), escapeshellarg($root . 'reset.php')), $output, $exitCode);
            self::assertSame(0, $exitCode, implode("\n", $output));
            // a reset doesn't move the head of the event store, so a new event wakes up the idle worker
            $output = [];
            exec(sprintf('DATABASE_DSN=%s %s %s 2>&1', escapeshellarg($environment['DATABASE_DSN']), escapeshellarg(PHP_BINARY), escapeshellarg($root . 'append-event.php')), $output, $exitCode);
            self::assertSame(0, $exitCode, implode("\n", $output));
            $deadline = microtime(true) + 20.0;
            while ($this->connection->fetchOne('SELECT status FROM subscriptions WHERE id = ?', ['some-projection']) !== 'ACTIVE' || (int) $this->connection->fetchOne('SELECT COUNT(*) FROM some_projection') < 5) {
                if (microtime(true) > $deadline) {
                    self::fail('The example did not rebuild the projection: ' . file_get_contents($stderr));
                }
                usleep(10_000);
            }
            proc_terminate($process, SIGTERM);
            $deadline = microtime(true) + 20.0;
            while (($status = proc_get_status($process))['running']) {
                if (microtime(true) > $deadline) {
                    self::fail('The example did not stop: ' . file_get_contents($stderr));
                }
                usleep(10_000);
            }
            self::assertSame(0, $status['exitcode'], (string) file_get_contents($stderr));
        } finally {
            proc_terminate($process, SIGKILL);
            proc_close($process);
            $this->connection->executeStatement('DROP TABLE IF EXISTS some_projection');
        }
    }

    #[Test]
    public function theDocumentedScalingQueryReturnsTheNumberOfWorkersThatCanDoWork(): void
    {
        $this->setupSubscriptions(['booting', 'lagging', 'almost', 'current', 'error']);
        $this->append(500);
        $positions = ['booting' => 0, 'lagging' => 399, 'almost' => 400, 'current' => 500, 'error' => 0];
        foreach ($positions as $id => $position) {
            $status = match ($id) {
                'booting' => SubscriptionStatus::BOOTING,
                'error' => SubscriptionStatus::ERROR,
                default => SubscriptionStatus::ACTIVE,
            };
            $this->connection->update('subscriptions', ['status' => $status->value, 'position' => $position], ['id' => SubscriptionId::fromString($id)->value]);
        }
        $document = Yaml::parseFile(__DIR__ . '/../../examples/kubernetes/keda-scaledobject.yaml');

        $result = $this->connection->fetchOne($document['spec']['triggers'][0]['metadata']['query']);

        self::assertSame(2, (int) $result, 'booting and lagging, "almost" is exactly at the threshold');
    }

    /**
     * @param list<string> $ids
     */
    private function setupSubscriptions(array $ids = self::SUBSCRIPTIONS): void
    {
        $subscribers = array_map(static fn(string $id) => Subscriber::create($id, static function (): void {}), $ids);
        $engine = new SubscriptionEngine(new DcbEventStoreAdapter($this->eventStore()), new DoctrineSubscriptionStore($this->connection, 'subscriptions'), Subscribers::fromArray($subscribers));
        $result = $engine->setup();
        self::assertNull($result->errors);
    }

    private function eventStore(): DoctrineEventStore
    {
        return new DoctrineEventStore(DoctrineEventStoreConfiguration::create($this->connection, 'events'));
    }

    private function append(int $count): void
    {
        $this->eventStore()->append(Events::fromArray(array_fill(0, $count, Event::create('SomethingHappened', '{}'))));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function startWorker(string $name, array $config): void
    {
        $stdout = $this->runDirectory . '/' . $name . '.out';
        $stderr = $this->runDirectory . '/' . $name . '.err';
        $config += ['name' => $name, 'subscriptions' => self::SUBSCRIPTIONS, 'limit' => 20, 'maxIdleWait' => 0.05, 'sleepUs' => 1000];
        $process = proc_open([PHP_BINARY, __DIR__ . '/worker.php', json_encode($config, JSON_THROW_ON_ERROR)], [1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start ' . $name);
        }
        $this->workers[$name] = ['process' => $process, 'stdout' => '', 'stderr' => '', 'exitCode' => null, 'signal' => null];
    }

    private function stopWorker(string $name): void
    {
        proc_terminate($this->workers[$name]['process'], SIGTERM);
        $this->waitForExit($name);
    }

    private function waitForExit(string $name, float $timeout = 30.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $status = proc_get_status($this->workers[$name]['process']);
            if (!$status['running']) {
                $this->workers[$name]['exitCode'] = $status['signaled'] ? null : $status['exitcode'];
                $this->workers[$name]['signal'] = $status['signaled'] ? $status['termsig'] : null;
                $this->workers[$name]['stdout'] = (string) file_get_contents($this->runDirectory . '/' . $name . '.out');
                $this->workers[$name]['stderr'] = (string) file_get_contents($this->runDirectory . '/' . $name . '.err');
                return;
            }
            if (microtime(true) > $deadline) {
                self::fail(sprintf('Worker %s did not exit: %s', $name, file_get_contents($this->runDirectory . '/' . $name . '.err')));
            }
            usleep(10_000);
        }
    }

    private function waitForRows(int $expected, float $timeout = 20.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (($rows = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projection')) < $expected) {
            if (microtime(true) > $deadline) {
                $stderr = array_map(fn(string $name) => $name . ': ' . file_get_contents($this->runDirectory . '/' . $name . '.err'), array_keys($this->workers));
                self::fail(sprintf(
                    "Expected %d rows, got %d. Workers:\n%s\nSubscriptions: %s\nConnections: %s",
                    $expected,
                    $rows,
                    implode("\n", $stderr),
                    json_encode($this->connection->fetchAllAssociative('SELECT id, status, position FROM subscriptions ORDER BY id')),
                    json_encode($this->connection->fetchAllAssociative('SELECT state, wait_event, query FROM pg_stat_activity WHERE pid <> pg_backend_pid() AND datname = current_database()')),
                ));
            }
            usleep(10_000);
        }
    }

    private function waitForActive(): void
    {
        $deadline = microtime(true) + 30.0;
        while ((int) $this->connection->fetchOne("SELECT COUNT(*) FROM subscriptions WHERE status = 'ACTIVE'") < count(self::SUBSCRIPTIONS)) {
            if (microtime(true) > $deadline) {
                self::fail('Subscriptions did not become active');
            }
            usleep(10_000);
        }
    }

    private function terminateOtherConnections(): void
    {
        $this->connection->fetchAllAssociative('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE pid <> pg_backend_pid() AND datname = current_database()');
    }

    private function assertEveryEventHandledExactlyOnce(int $numberOfEvents): void
    {
        $expected = range(1, $numberOfEvents);
        foreach (self::SUBSCRIPTIONS as $id) {
            $positions = array_map(intval(...), $this->connection->fetchFirstColumn('SELECT position FROM projection WHERE subscription = ? ORDER BY sequence', [$id]));
            self::assertSame($expected, $positions, 'Each event in order and exactly once for ' . $id);
        }
    }

    private function assertAllSubscriptionsActiveAt(int $position): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, status, position FROM subscriptions') as $row) {
            self::assertSame('ACTIVE', $row['status'], (string) $row['id']);
            self::assertSame($position, (int) $row['position'], (string) $row['id']);
        }
    }
}
