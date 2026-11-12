<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineWorker;

use Exception;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Random\Randomizer;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpFinished;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpRunFailed;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\EngineEvent;
use Wwwision\SubscriptionEngine\Engine\ProcessedResult;
use Wwwision\SubscriptionEngine\Engine\SubscriptionEngineCriteria;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngine\SubscriptionEngine;

/**
 * Claims free subscriptions, catches them up in bounded batches and waits when there is nothing to do. See docs/decisions.md
 */
final class Worker
{
    private const LOCKED_SLEEP_MIN_SECONDS = 0.1;
    private const LOCKED_SLEEP_MAX_SECONDS = 0.5;
    private const MAX_RUNTIME_JITTER = 0.1;
    private const BACKOFF_JITTER = 0.2;
    private const FAILED_RUN_MAX_DELAY_SECONDS = 60.0;
    private const FAILURE_MAX_DELAY_SECONDS = 30.0;
    private const GIVE_UP_AFTER_SECONDS = 300.0;
    private const ERROR_REPORT_INTERVAL_SECONDS = 300.0;

    private readonly LoggerInterface $logger;
    private readonly ClockInterface $clock;
    private readonly Waiter $sleeper;
    private readonly Randomizer $randomizer;

    private int $iterations = 0;
    private float|null $deadline = null;
    private bool $signalReceived = false;
    private bool $recoverRequired = false;
    private float|null $failingSince = null;
    private int $consecutiveFailures = 0;
    private float|null $nextErrorReport = null;

    /**
     * Subscriptions with a failed run, by id. They are left out of claims until the delay has expired
     *
     * @var array<string, array{attempts: int, until: float}>
     */
    private array $backoffs = [];

    /**
     * @param SubscriptionEngine<object> $engine
     * @param ClockInterface|null $clock For tests
     * @param Waiter|null $sleeper For tests: Used for sleeps that must not be ended by new events
     * @param Randomizer|null $randomizer For tests
     */
    public function __construct(
        private readonly SubscriptionEngine $engine,
        private readonly Waiter $waiter,
        private readonly WorkerOptions $options,
        LoggerInterface|null $logger = null,
        ClockInterface|null $clock = null,
        Waiter|null $sleeper = null,
        Randomizer|null $randomizer = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock ?? new SystemClock();
        $this->sleeper = $sleeper ?? new SleepWaiter();
        $this->randomizer = $randomizer ?? new Randomizer();
        $this->engine->onEngineEvent($this->onEngineEvent(...));
        if ($logger !== null) {
            $this->engine->onEngineEvent($this->logEngineEvent(...));
        }
    }

    public function run(): StopReason
    {
        $this->iterations = 0;
        $this->nextErrorReport = null;
        $this->deadline = $this->options->maxRuntime !== null
            ? $this->now() + $this->options->maxRuntime * (1 + $this->randomizer->getFloat(0.0, self::MAX_RUNTIME_JITTER))
            : null;
        $restoreSignalHandlers = $this->installSignalHandlers();
        $this->logger->info(sprintf('Worker started (pid: %d)', getmypid()));
        try {
            while (true) {
                $stopReason = $this->stopReason() ?? $this->cycleWithRecovery();
                if ($stopReason !== null) {
                    $this->logger->info(sprintf('Worker stopped: %s', $stopReason->value));
                    return $stopReason;
                }
            }
        } finally {
            $restoreSignalHandlers();
        }
    }

    /**
     * The handlers only set a flag, so that a running batch is finished and its transaction committed
     *
     * @return callable(): void restores the previous state
     */
    private function installSignalHandlers(): callable
    {
        $this->signalReceived = false;
        if (!$this->options->handleSignals) {
            return static function (): void {};
        }
        if (!function_exists('pcntl_async_signals')) {
            throw new LogicException('Handling signals requires ext-pcntl. Install it, or disable this with the option handleSignals: false', 1793840001);
        }
        $previousAsyncSignals = pcntl_async_signals(true);
        $previousHandlers = [];
        foreach ([SIGTERM, SIGINT] as $signal) {
            $previousHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, function (): void {
                $this->signalReceived = true;
            });
        }
        return static function () use ($previousAsyncSignals, $previousHandlers): void {
            foreach ($previousHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($previousAsyncSignals);
        };
    }

    /**
     * @throws WorkerFailed
     */
    private function cycleWithRecovery(): StopReason|null
    {
        try {
            if ($this->options->onHeartbeat !== null) {
                ($this->options->onHeartbeat)();
            }
            $stopReason = $this->cycle();
            if ($this->consecutiveFailures > 0) {
                $this->logger->info(sprintf('Recovered after %d failure%s', $this->consecutiveFailures, $this->consecutiveFailures > 1 ? 's' : ''));
            }
            $this->failingSince = null;
            $this->consecutiveFailures = 0;
            return $stopReason;
        } catch (Exception $e) {
            $this->failingSince ??= $this->now();
            if ($this->now() - $this->failingSince >= self::GIVE_UP_AFTER_SECONDS) {
                throw WorkerFailed::after(self::GIVE_UP_AFTER_SECONDS, $e);
            }
            $this->consecutiveFailures++;
            $this->recover();
            $delay = $this->withBackoffJitter(min(2 ** ($this->consecutiveFailures - 1), self::FAILURE_MAX_DELAY_SECONDS));
            $this->logger->warning(sprintf('Cycle failed, trying again in %.1f seconds: %s', $delay, $e->getMessage()));
            $this->sleeper->wait($this->untilDeadline($delay));
            return null;
        }
    }

    private function cycle(): StopReason|null
    {
        $this->reportSubscriptionsInError();
        $criteria = $this->claimCriteria();
        if ($criteria === null) {
            $this->waiter->wait($this->idleWaitSeconds());
            return null;
        }
        $hasPendingEvents = false;
        $allLocked = false;
        foreach ([$this->engine->bootNext(...), $this->engine->catchUpNextActive(...)] as $catchUp) {
            $result = $catchUp($criteria, $this->options->limit);
            if ($this->recoverRequired) {
                $this->recover();
            }
            $stopReason = $this->stopReasonAfterBatch($result);
            if ($stopReason !== null) {
                return $stopReason;
            }
            $hasPendingEvents = $hasPendingEvents || $result->hasPendingEvents;
            $allLocked = $allLocked || $result->allSubscriptionsLocked;
        }
        if ($hasPendingEvents) {
            $this->waiter->wait(0.0);
            return null;
        }
        if ($allLocked) {
            $this->waiter->wait(0.0);
            $this->sleeper->wait($this->untilDeadline($this->randomizer->getFloat(self::LOCKED_SLEEP_MIN_SECONDS, self::LOCKED_SLEEP_MAX_SECONDS)));
            return null;
        }
        $this->waiter->wait($this->idleWaitSeconds());
        return null;
    }

    /**
     * @return SubscriptionEngineCriteria|null The criteria for the next claims, NULL if all subscriptions are backing off
     */
    private function claimCriteria(): SubscriptionEngineCriteria|null
    {
        $now = $this->now();
        $backingOff = array_keys(array_filter($this->backoffs, static fn(array $backoff) => $backoff['until'] > $now));
        if ($backingOff === []) {
            return $this->options->criteria ?? SubscriptionEngineCriteria::noConstraints();
        }
        $ids = [];
        foreach ($this->engine->getSubscriptions($this->options->criteria) as $subscription) {
            if (in_array($subscription->status, [SubscriptionStatus::ACTIVE, SubscriptionStatus::BOOTING], true) && !in_array($subscription->id->value, $backingOff, true)) {
                $ids[] = $subscription->id;
            }
        }
        return $ids !== [] ? SubscriptionEngineCriteria::create($ids) : null;
    }

    private function idleWaitSeconds(): float
    {
        $seconds = $this->options->maxIdleWait;
        $now = $this->now();
        foreach ($this->backoffs as $backoff) {
            if ($backoff['until'] > $now) {
                $seconds = min($seconds, $backoff['until'] - $now);
            }
        }
        return $this->untilDeadline($seconds);
    }

    /**
     * @param EngineEvent<object|void> $event
     */
    private function onEngineEvent(EngineEvent $event): void
    {
        if ($event instanceof CatchUpRunFailed) {
            $attempts = ($this->backoffs[$event->subscription->id->value]['attempts'] ?? 0) + 1;
            $delay = $this->withBackoffJitter(min(2 ** ($attempts - 1), self::FAILED_RUN_MAX_DELAY_SECONDS));
            $this->backoffs[$event->subscription->id->value] = ['attempts' => $attempts, 'until' => $this->now() + $delay];
            $this->recoverRequired = true;
            $this->logger->warning(sprintf('Backing off from subscription "%s" for %.1f seconds (attempt %d)', $event->subscription->id->value, $delay, $attempts));
        }
        if ($event instanceof CatchUpFinished) {
            foreach ($event->subscriptions as $subscription) {
                unset($this->backoffs[$subscription->id->value]);
            }
        }
    }

    private function recover(): void
    {
        $this->recoverRequired = false;
        if ($this->options->recover === null) {
            return;
        }
        try {
            ($this->options->recover)();
        } catch (Exception $e) {
            // if this was necessary, the next attempt fails as well
            $this->logger->warning(sprintf('The recover callback failed: %s', $e->getMessage()));
        }
    }

    /**
     * Subscriptions in ERROR are never retried, and without a report they would go unnoticed
     */
    private function reportSubscriptionsInError(): void
    {
        $now = $this->now();
        if ($this->nextErrorReport !== null && $now < $this->nextErrorReport) {
            return;
        }
        foreach ($this->engine->getSubscriptions($this->options->criteria) as $subscription) {
            if ($subscription->status === SubscriptionStatus::ERROR) {
                $this->logger->warning(sprintf('Subscription "%s" is in status ERROR and needs a reset: %s', $subscription->id->value, $subscription->error->errorMessage ?? 'unknown error'));
            }
        }
        $this->nextErrorReport = $now + self::ERROR_REPORT_INTERVAL_SECONDS;
    }

    /**
     * @param EngineEvent<object|void> $event
     */
    private function logEngineEvent(EngineEvent $event): void
    {
        $this->logger->log(match ($event->logLevel()) {
            LOG_EMERG => LogLevel::EMERGENCY,
            LOG_ALERT => LogLevel::ALERT,
            LOG_CRIT => LogLevel::CRITICAL,
            LOG_ERR => LogLevel::ERROR,
            LOG_WARNING => LogLevel::WARNING,
            LOG_NOTICE => LogLevel::NOTICE,
            LOG_INFO => LogLevel::INFO,
            default => LogLevel::DEBUG,
        }, (string) $event);
    }

    private function withBackoffJitter(float $seconds): float
    {
        return $seconds * $this->randomizer->getFloat(1 - self::BACKOFF_JITTER, 1 + self::BACKOFF_JITTER);
    }

    private function stopReasonAfterBatch(ProcessedResult $result): StopReason|null
    {
        if ($result->numberOfProcessedEvents > 0) {
            $this->iterations++;
            if ($this->iterations === $this->options->maxIterations) {
                return StopReason::MaxIterations;
            }
            if ($this->options->memoryLimit !== null && memory_get_usage(true) >= $this->options->memoryLimit) {
                return StopReason::MemoryLimit;
            }
        }
        return $this->stopReason();
    }

    private function stopReason(): StopReason|null
    {
        if ($this->signalReceived) {
            return StopReason::Signal;
        }
        if ($this->deadline !== null && $this->now() >= $this->deadline) {
            return StopReason::MaxRuntime;
        }
        return null;
    }

    private function untilDeadline(float $seconds): float
    {
        return $this->deadline !== null ? max(0.0, min($seconds, $this->deadline - $this->now())) : $seconds;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
