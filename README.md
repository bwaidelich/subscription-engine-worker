# Worker for the Subscription Engine

Long-running worker loop for [wwwision/subscription-engine](https://packagist.org/packages/wwwision/subscription-engine): Run N identical PHP processes with Supervisor or Kubernetes, and scale by changing only that number.

Each worker claims a free subscription, catches it up in a bounded batch, and waits when there is nothing to do.

> [!NOTE]
> This package requires subscription engine 2.0, which is not released yet

## Usage

```php
$worker = new Worker($engine, new SleepWaiter(), WorkerOptions::create(
    limit: 500,
    maxRuntime: 3600.0,
    memoryLimit: 256 * 1024 * 1024,
    recover: $connection->close(...),
), $logger);
$stopReason = $worker->run();
```

The worker never calls `setup()` or `reset()`: Concurrent setups can fail, so `setup()` is a deploy step, and `NEW` subscriptions wait for it. See [examples](./examples) for complete scripts and configurations.

### Options

| Option | Default | Meaning |
|---|---|---|
| `limit` | `500` | Maximum number of events per batch |
| `criteria` | all | Restricts this worker to certain subscriptions |
| `maxIdleWait` | `30.0` | Seconds an idle worker waits at most before it tries to claim again |
| `maxRuntime` | off | Seconds until the worker stops, plus up to 10% of jitter so that replicas don't recycle together |
| `maxIterations` | off | Number of catch-up calls that processed events until the worker stops |
| `memoryLimit` | off | Bytes until the worker stops, checked after a batch |
| `recover` | none | Called after a failure, for example to close a connection so that it reconnects |
| `onHeartbeat` | none | Called once per cycle, for example to touch a file for a liveness probe |
| `handleSignals` | `true` | Whether SIGTERM and SIGINT stop the worker gracefully. Requires `ext-pcntl`, otherwise `run()` throws |

`run()` returns a `StopReason`. Exit with code 0 and let the process manager restart the worker. It throws `WorkerFailed` after 5 minutes without a successful cycle, an uncaught exception results in a non-zero exit code.

### Waiting for events

`SleepWaiter` polls at the rate of `maxIdleWait`, so lower that option when using it. To wake up right away, `CallbackWaiter` connects anything else that can wait, for example LISTEN of [wwwision/dcb-eventstore-doctrine](https://packagist.org/packages/wwwision/dcb-eventstore-doctrine):

```php
$eventStore = new DoctrineEventStore(DoctrineEventStoreConfiguration::create($connection, 'events')->withNotifications());
$waiter = new CallbackWaiter($eventStore->createWaiter($dedicatedConnection)->waitForNewEvents(...));
```

The worker tries to claim after each wait, however it ended. While events are pending it calls `wait(0.0)` after each batch, because a listener that doesn't read its notifications fills the queue of PostgreSQL, and appends fail once it is full.

## Scaling

A subscription runs on one worker at a time, so **workers beyond the number of subscriptions add no throughput**. They only find locked rows, and check again every 100 to 500 ms while the others are busy.

### KEDA

KEDA runs a query and sets `replicas = result / targetQueryValue`. With a `targetQueryValue` of `1`, this query returns the number of workers that can do work, which is the number of subscriptions that are behind:

```sql
SELECT COUNT(*) FROM subscriptions WHERE status = 'BOOTING' OR (status = 'ACTIVE' AND (SELECT COALESCE(MAX(sequence_number), 0) FROM events) - position > 100)
```

A sum of events would start the maximum number of workers for a single lagging subscription. The threshold of 100 ignores normal lag, and `ERROR` is left out because more workers don't fix it. The event table depends on the event store, this one belongs to `wwwision/dcb-eventstore-doctrine`.

Use `minReplicaCount: 1`, with 0 workers new events wait for the next check of KEDA, and a `maxReplicaCount` no higher than the number of subscriptions.

## Guarantees

**Exactly once** only applies to handlers that write through the connection of the subscription store: Their changes are part of the transaction of the catch-up. Handlers that write anywhere else get at-least-once, as a batch is repeated if a worker is killed or a run fails.

Events of a subscription are handled in order. There is no order between subscribers, so a handler must not rely on the projection of another one.

## Failures

| What | Reaction |
|---|---|
| A subscriber throws, and the transaction is intact | The subscription goes to `ERROR` and is **never retried**: The event may be partially applied. The worker logs a warning at startup and every 5 minutes, recovery takes a `reset()` |
| A run fails after the claim, for example with any SQL error of a handler on PostgreSQL | Rolled back, the status stays. `recover` is called, the subscription backs off for 1 to 60 seconds and the others continue. This repeats forever if the error does, only the logs show it |
| The database or the waiter fails | `recover` is called, the worker backs off for 1 to 30 seconds and tries again. After 5 minutes `run()` throws `WorkerFailed` |
| A PHP `Error` | Escapes right away, it is a bug |

The backoff is kept per process, so N workers retry independently. A worker that hangs on a half-open connection can't detect that on its own: Enable TCP keepalives on the connection and use `onHeartbeat` for a liveness probe.

## Stopping

SIGTERM and SIGINT finish the running batch and end a wait early. Configure a grace period above your worst-case batch, `limit` × the duration of your handler. If the process gets killed anyway the batch is rolled back and repeated by another worker, so a second signal does nothing special.

Commands have to reach PHP directly: Use the exec form in containers, without a shell around it.

## Logging

Passing a logger logs the start, the stop reason, backoffs and recoveries, and all engine events with their level.

> [!WARNING]
> `SubscriberHandledEvent` is logged at `info`, which is one line per event and subscription. Use `warning` or above in production unless you want that.

## Contribution

```bash
composer test
docker run -d -e POSTGRES_PASSWORD=test -e POSTGRES_DB=test -p 127.0.0.1:5432:5432 postgres:17
TEST_DATABASE_DSN=pdo-pgsql://postgres:test@127.0.0.1:5432/test composer test:phpunit
```

See [docs/decisions.md](./docs/decisions.md) for the reasoning behind this package.

## License

See [LICENSE](./LICENSE)
