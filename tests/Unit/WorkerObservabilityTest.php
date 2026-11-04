<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use RuntimeException;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngineWorker\Tests\Unit\Fixture\CollectingLogger;
use Wwwision\SubscriptionEngineWorker\WorkerOptions;

final class WorkerObservabilityTest extends WorkerTestCase
{
    private CollectingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new CollectingLogger();
    }

    #[Test]
    public function startAndStopReasonAreLogged(): void
    {
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);

        $this->worker($this->engine('active'), WorkerOptions::create(maxRuntime: 1.0, handleSignals: false), $this->logger)->run();

        $messages = $this->logger->messages(LogLevel::INFO);
        self::assertStringContainsString('started', $messages[0]);
        self::assertStringContainsString('stopped: max runtime', end($messages));
    }

    #[Test]
    public function engineEventsAreLoggedWithTheirLevel(): void
    {
        $this->appendEvents(1);
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $this->store->_failOnUpdateOf('active');

        $this->worker($this->engine('active'), WorkerOptions::create(maxRuntime: 0.5, handleSignals: false), $this->logger)->run();

        self::assertNotEmpty(array_filter($this->logger->messages(LogLevel::INFO), static fn(string $message) => str_contains($message, 'processed event')), 'SubscriberHandledEvent');
        self::assertNotEmpty(array_filter($this->logger->messages(LogLevel::ERROR), static fn(string $message) => str_contains($message, 'failed')), 'CatchUpRunFailed');
    }

    #[Test]
    public function failuresAndTheRecoveryFromThemAreLogged(): void
    {
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $this->flakyStore->down = true;
        $this->sleeper->onWait = function (): void {
            $this->flakyStore->down = false;
        };

        $this->worker($this->engine('active'), WorkerOptions::create(maxRuntime: 2.0, handleSignals: false), $this->logger)->run();

        self::assertStringContainsString('Connection lost', $this->logger->messages(LogLevel::WARNING)[0]);
        self::assertNotEmpty(array_filter($this->logger->messages(LogLevel::INFO), static fn(string $message) => str_contains($message, 'Recovered after 1 failure')));
    }

    #[Test]
    public function aFailingRecoverCallbackIsLogged(): void
    {
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $this->flakyStore->down = true;
        $this->sleeper->onWait = function (): void {
            $this->flakyStore->down = false;
        };
        $options = WorkerOptions::create(maxRuntime: 2.0, recover: static function (): void {
            throw new RuntimeException('Close failed');
        }, handleSignals: false);

        $this->worker($this->engine('active'), $options, $this->logger)->run();

        self::assertNotEmpty(array_filter($this->logger->messages(LogLevel::WARNING), static fn(string $message) => str_contains($message, 'Close failed')));
    }

    #[Test]
    public function subscriptionsInErrorAreReportedAtStartupAndEveryFiveMinutesButNotRetried(): void
    {
        $this->appendEvents(2);
        $this->givenSubscription('broken', SubscriptionStatus::ERROR);
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE, 2);

        $this->worker($this->engine('broken', 'active'), WorkerOptions::create(maxRuntime: 700.0, handleSignals: false), $this->logger)->run();

        $warnings = array_values(array_filter($this->logger->messages(LogLevel::WARNING), static fn(string $message) => str_contains($message, 'broken')));
        self::assertCount(3, $warnings, 'At 0, 300 and 600 seconds, the jitter of the runtime adds at most 70');
        self::assertArrayNotHasKey('broken', $this->handled);
    }

    #[Test]
    public function theHeartbeatIsCalledOnEveryCycle(): void
    {
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $beats = 0;
        $options = WorkerOptions::create(maxIdleWait: 10.0, maxRuntime: 25.0, onHeartbeat: static function () use (&$beats): void {
            $beats++;
        }, handleSignals: false);

        $this->worker($this->engine('active'), $options)->run();

        self::assertGreaterThanOrEqual(3, $beats);
        self::assertSame(count($this->waiter->timeouts), $beats);
    }

    #[Test]
    public function theHeartbeatContinuesWhileTheDatabaseIsGone(): void
    {
        $this->givenSubscription('active', SubscriptionStatus::ACTIVE);
        $this->flakyStore->down = true;
        $beats = 0;
        $options = WorkerOptions::create(maxRuntime: 10.0, onHeartbeat: static function () use (&$beats): void {
            $beats++;
        }, handleSignals: false);

        $this->worker($this->engine('active'), $options)->run();

        self::assertGreaterThanOrEqual(3, $beats);
    }
}
