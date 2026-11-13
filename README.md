# Worker for the Subscription Engine

Keeps your event-sourced read models up to date in the background: Start the same PHP script as often as you like, and scale by changing only that number.

## What does it do?

In an [event-sourced](https://martinfowler.com/eaaDev/EventSourcing.html) application, changes are stored as a list of events in an **event store**. To query them, you build tables from these events:

- A **subscriber** is your code that reacts to events, for example by writing a row to a table. The result is often called a **projection** or read model
- A **subscription** remembers how far a subscriber got
- The [subscription engine](https://github.com/bwaidelich/subscription-engine) passes new events to the subscribers

The engine only does this when something calls it. This package is that something, a long-running process that:

- picks a subscription that no other worker is busy with and processes up to 500 of its events
- waits while there is nothing to do
- stops cleanly when your deployment asks it to
- retries when something fails, for example if the database is restarted

## Quick start

This runs a worker with the example subscriber against a temporary PostgreSQL database.

**You need:** PHP 8.3+ with the extensions [`pcntl`](https://www.php.net/manual/en/book.pcntl.php) and `pdo_pgsql`, [Composer](https://getcomposer.org) and [Docker](https://www.docker.com).

Run this in a checkout of this repository:

```bash
# 1. Install the dependencies and start a database
composer install
docker run -d --name subscription-worker-db -e POSTGRES_PASSWORD=test -e POSTGRES_DB=test -p 127.0.0.1:5432:5432 postgres:17
until docker exec subscription-worker-db pg_isready -q -h 127.0.0.1; do sleep 1; done

# 2. Tell the example scripts where the database is
export DATABASE_DSN='pdo-pgsql://postgres:test@127.0.0.1:5432/test'

# 3. Create the tables and register the subscriber, once per deployment
php examples/setup.php

# 4. Start a worker in the background
php examples/worker.php &

# 5. Store an event, the worker handles it right away
php examples/append-event.php
sleep 1
docker exec subscription-worker-db psql -U postgres -d test -c 'SELECT * FROM some_projection'

# 6. Stop the worker
kill $!
```

Step 5 prints the row that the subscriber has written for the event:

```
 position 
----------
        1
(1 row)
```

The how-tos below continue from here, so keep the database for now. To remove it afterwards, run `docker rm -f subscription-worker-db`.

The scripts in [examples](./examples) are small on purpose, copy them to your application:

| File | Purpose |
|---|---|
| [`bootstrap.php`](./examples/bootstrap.php) | Your part: Database connection, event store and subscribers |
| [`setup.php`](./examples/setup.php) | Creates tables and registers subscribers, run it once per deployment |
| [`worker.php`](./examples/worker.php) | The worker, start as many of them as you need |
| [`append-event.php`](./examples/append-event.php) | Stores a test event |
| [`reset.php`](./examples/reset.php) | Rebuilds a projection from the first event |

## How-to guides

- **Develop:** [Add your own subscriber](#add-your-own-subscriber) · [See what a worker is doing](#see-what-a-worker-is-doing)
- **Run:** [Several workers](#run-several-workers) · [Supervisor](#run-workers-with-supervisor) · [Kubernetes](#run-workers-on-kubernetes) · [KEDA](#scale-automatically-on-kubernetes-with-keda)
- **Operate:** [Deploy a new version](#deploy-a-new-version) · [Rebuild a projection](#rebuild-a-projection) · [Restart workers regularly](#restart-workers-regularly) · [Reserve workers](#reserve-workers-for-certain-subscribers)

Something isn't working? See [Troubleshooting](#troubleshooting).

### Add your own subscriber

Add an entry to the list of subscribers in `examples/bootstrap.php`:

```php
Subscriber::create(
    'event-log',
    static function (SequencedEvent $event) use ($connection): void {
        $connection->insert('event_log', ['position' => $event->position->value, 'type' => $event->event->type->value]);
    },
    setup: static function () use ($connection): void {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS event_log (position BIGINT PRIMARY KEY, type TEXT NOT NULL)');
    },
    reset: static function () use ($connection): void {
        $connection->executeStatement('TRUNCATE event_log');
    },
),
```

- `setup` creates what the subscriber needs
- `reset` removes its data, so that the projection can be rebuilt
- `filter` (optional) passes only matching events to the subscriber. As a worker catches up one subscription at a time, the event store only returns those events, for example `filter: Query::fromItems(QueryItem::create(eventTypes: 'SomethingHappened'))`. [Details](https://github.com/bwaidelich/subscription-engine/blob/main/docs/how-it-works.md#filtered-subscribers)

With all workers stopped, run the setup again and start them. The new subscriber starts with the first event, so it also receives the events from the past:

```bash
php examples/setup.php
php examples/worker.php &
sleep 2
docker exec subscription-worker-db psql -U postgres -d test -c 'SELECT * FROM event_log'
kill $!
```

> [!IMPORTANT]
> Use `$connection`, the connection from `bootstrap.php`, for everything a subscriber writes. Its changes are then saved together with the progress of the subscription, so that each event counts exactly once. With anything else, for example when sending an email, an event can be handled more than once. [Details](./docs/how-it-works.md#what-is-guaranteed)

### See what a worker is doing

The example worker doesn't log anything. Pass any [PSR-3](https://www.php-fig.org/psr/psr-3/) logger as the fourth argument of `Worker`, for example [Monolog](https://github.com/Seldaek/monolog) (`composer require monolog/monolog`):

```php
<?php

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Wwwision\SubscriptionEngineWorker\SleepWaiter;
use Wwwision\SubscriptionEngineWorker\Worker;
use Wwwision\SubscriptionEngineWorker\WorkerOptions;

['connection' => $connection, 'engine' => $engine] = require __DIR__ . '/bootstrap.php';

$logger = new Logger('worker', [new StreamHandler('php://stderr', Level::Warning)]);
$options = WorkerOptions::create(maxIdleWait: 1.0, recover: $connection->close(...));
(new Worker($engine, new SleepWaiter(), $options, $logger))->run();
```

> [!WARNING]
> Use `Level::Warning` or above in production. `Level::Info` is useful for trying things out, but it writes one line per event and subscriber.

This example also shows the simplest way to wait for events. `SleepWaiter` checks for new events every `maxIdleWait` seconds, and works with every event store. `examples/worker.php` instead uses the waiter of the event store, which checks for new events every second on a connection of its own, see [Waiting for events](./docs/how-it-works.md#waiting-for-events).

### Run several workers

Start the script several times. There is nothing to configure, each worker picks a subscription that is free:

```bash
php examples/worker.php &
php examples/worker.php &
php examples/append-event.php
sleep 1
docker exec subscription-worker-db psql -U postgres -d test -c 'SELECT id, status, position FROM subscriptions'
pkill -f examples/worker.php
```

> [!TIP]
> Only one worker can work on a subscription at a time, so **more workers than subscribers don't make anything faster**. With 4 subscribers, run at most 4 workers.

In production, let a process manager start the workers and restart them when they exit, as described in the next two guides.

### Run workers with Supervisor

[Supervisor](https://supervisord.org/) keeps programs running on a server.

1. Run `examples/setup.php` once, before the first start
2. Copy [`examples/supervisor/worker.conf`](./examples/supervisor/worker.conf) to the configuration directory of Supervisor, usually `/etc/supervisor/conf.d/`, and adjust the path `/app/examples/worker.php`
3. Add the database to the program, Supervisor doesn't read your shell's environment:

   ```ini
   environment=DATABASE_DSN="pdo-pgsql://user:password@your-database-host:5432/your-database"
   ```

4. Load the configuration:

   ```bash
   supervisorctl reread
   supervisorctl update
   supervisorctl status
   ```

To change the number of workers, change `numprocs` and run these commands again.

### Run workers on Kubernetes

[Kubernetes](https://kubernetes.io/docs/concepts/overview/) runs containers on a cluster. You need a container image of your application, called `your-app:latest` in the examples, in which the scripts are located at `/app/examples/`.

```bash
# Once: Store the database address as a secret
kubectl create secret generic subscription-worker --from-literal=database-dsn='pdo-pgsql://user:password@your-database-host:5432/your-database'

# On each deployment: Roll out the workers, then run the setup
kubectl apply -f examples/kubernetes/worker-deployment.yaml
kubectl rollout status deployment/subscription-worker
kubectl delete job subscription-setup --ignore-not-found
kubectl apply -f examples/kubernetes/setup-job.yaml
kubectl wait --for=condition=complete --timeout=120s job/subscription-setup
```

[Why in this order?](#deploy-a-new-version) On the very first deployment, the workers keep retrying until the setup has created the tables.

- [`setup-job.yaml`](./examples/kubernetes/setup-job.yaml) is a one-off [Job](https://kubernetes.io/docs/concepts/workloads/controllers/job/) that runs the setup
- [`worker-deployment.yaml`](./examples/kubernetes/worker-deployment.yaml) is a [Deployment](https://kubernetes.io/docs/concepts/workloads/controllers/deployment/) that keeps the workers running. To change the number of workers, change `replicas`

In your own Dockerfile or manifest, start PHP directly as in these examples (`command: ["php", "..."]`). With a shell in between, PHP might not receive the stop signal.

### Scale automatically on Kubernetes with KEDA

Instead of a fixed number of workers, [KEDA](https://keda.sh) (Kubernetes Event-Driven Autoscaling) can start workers only when there is work. It regularly asks an external source, in this case [your database](https://keda.sh/docs/latest/scalers/postgresql/), and adjusts the `replicas` of the Deployment.

1. [Install KEDA](https://keda.sh/docs/latest/deploy/) on your cluster
2. Fill in the database details in [`examples/kubernetes/keda-scaledobject.yaml`](./examples/kubernetes/keda-scaledobject.yaml)
3. KEDA reads the password from an environment variable of the worker, so add `DATABASE_PASSWORD` to the `env` list in `worker-deployment.yaml`
4. Set `maxReplicaCount` to your number of subscribers
5. Run `kubectl apply -f examples/kubernetes/keda-scaledobject.yaml`

KEDA runs this query, which returns the number of subscriptions that are more than 100 events behind or still processing their history:

```sql
SELECT COUNT(*) FROM subscriptions WHERE status = 'BOOTING' OR (status = 'ACTIVE' AND (SELECT COALESCE(MAX(sequence_number), 0) FROM events) - position > 100)
```

The table and column of the events belong to [wwwision/dcb-eventstore-doctrine](https://github.com/bwaidelich/dcb-eventstore-doctrine), adjust them for other event stores. [Why this query?](./docs/how-it-works.md#automatic-scaling)

> [!NOTE]
> The Kubernetes and KEDA manifests are checked automatically for their settings, and the query is tested against a database, but they aren't run on a real cluster.

### Deploy a new version

> [!WARNING]
> A worker with the old code treats a subscriber it doesn't know as removed, and sets it to `DETACHED`. So **don't run the setup for a new subscriber while old workers are running**.

With Supervisor, stop the workers during the setup:

```bash
supervisorctl stop 'subscription-worker:*'
php examples/setup.php
supervisorctl start 'subscription-worker:*'
```

To deploy without a pause, as in the [Kubernetes guide](#run-workers-on-kubernetes), first replace all workers and run the setup afterwards. New subscribers then start with the next event, or after `maxIdleWait` at the latest, and the others aren't interrupted.

**How long does stopping take?** On a stop signal (SIGTERM, or SIGINT with Ctrl+C), a worker finishes its current batch and exits. If that takes longer than your process manager allows, the worker gets killed. Nothing is lost in that case: The batch is rolled back and another worker repeats it. To avoid that, set `stopwaitsecs` (Supervisor) or `terminationGracePeriodSeconds` (Kubernetes) to more than `limit` × the duration of your slowest handler. The examples use 60 seconds.

### Rebuild a projection

For example after fixing a bug in a subscriber, or to get a subscription out of the status `ERROR` or `DETACHED`. Workers can keep running:

```bash
php examples/reset.php some-projection
```

This calls the `reset` function of the subscriber and starts over with the first event. An idle worker notices that with the next event, or after `maxIdleWait` at the latest (30 seconds by default).

If a subscriber has no `reset` function, its old rows stay in place. In this example, the rebuild would then run into a duplicate key and retry forever.

> [!WARNING]
> Workers pass all past events to a subscriber that was reset, even if it has the run mode `FROM_NOW`. A subscriber that sends emails would send them again. To skip the past events, stop the workers and call `reset()` followed by `setup()` for that subscriber.

### Restart workers regularly

Long-running PHP processes can slowly use up more memory. Let a worker exit on its own, the process manager starts a new one. `examples/worker.php` does this after an hour or at 256 MB:

```php
$options = WorkerOptions::create(
    maxRuntime: 3600.0,
    memoryLimit: 256 * 1024 * 1024,
);
```

`run()` returns a [`StopReason`](./src/StopReason.php) in that case, so let your script exit with code 0.

### Reserve workers for certain subscribers

For example to keep a slow subscriber from taking up all your workers:

```php
use Wwwision\SubscriptionEngine\Engine\SubscriptionEngineCriteria;

$options = WorkerOptions::create(
    criteria: SubscriptionEngineCriteria::create(['some-projection']),
);
```

## Options

All options of `WorkerOptions::create()` are optional:

| Option | Default | Meaning |
|---|---|---|
| `limit` | `500` | Maximum number of events per batch |
| `criteria` | all | Restricts this worker to certain subscriptions |
| `maxIdleWait` | `30.0` | Seconds an idle worker waits at most before it checks for work again. Lower this when using `SleepWaiter` |
| `maxRuntime` | off | Seconds until the worker stops, plus up to 10% so that workers don't all restart at once |
| `maxIterations` | off | Number of batches with events until the worker stops |
| `memoryLimit` | off | Bytes of memory until the worker stops, checked after each batch |
| `recover` | none | Called after a failure. Use `$connection->close(...)`, so that the connection is established again |
| `onHeartbeat` | none | Called regularly, for example to touch a file for a [liveness probe](https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/) |
| `handleSignals` | `true` | Whether SIGTERM and SIGINT stop the worker cleanly. Requires `ext-pcntl`, otherwise `run()` throws |

## Troubleshooting

| What you see | Why | What to do |
|---|---|---|
| A new subscriber receives no events | It isn't registered yet, workers never run the setup themselves | Stop the workers, run `examples/setup.php` and start them again |
| The status of a new subscriber is `DETACHED` | The setup ran while old workers were still running, see [Deploy a new version](#deploy-a-new-version) | Once all workers run the new code, [rebuild the projection](#rebuild-a-projection). Running the setup again doesn't help |
| Log: `Subscription "…" is in status ERROR and needs a reset` | A subscriber threw an exception. It might have applied the event partially, so it isn't retried | Fix the bug and deploy it, then [rebuild the projection](#rebuild-a-projection) |
| Log, again and again: `Backing off from subscription "…"` | Processing fails, often because of an SQL error in a subscriber. This is retried forever, and other subscribers continue | Fix the cause, it recovers on its own after that |
| The worker exits with `WorkerFailed` | Database unreachable for 5 minutes | Fix the database, the process manager restarts the worker |
| `Handling signals requires ext-pcntl` | The extension is missing | Install it, or disable this with `handleSignals: false` |
| `The environment variable DATABASE_DSN is not set` | The example scripts read the database address from there | Set it, see the [quick start](#quick-start) |
| Some workers never get any work | There are more workers than subscribers | Nothing, or use fewer workers |

## Learn more

- [How it works](./docs/how-it-works.md): The loop, guarantees, failure handling and scaling in detail
- [Decisions](./docs/decisions.md): The reasoning behind this package

## Contribution

```bash
composer test
docker run -d -e POSTGRES_PASSWORD=test -e POSTGRES_DB=test -p 127.0.0.1:5432:5432 postgres:17
TEST_DATABASE_DSN=pdo-pgsql://postgres:test@127.0.0.1:5432/test composer test:phpunit
```

## License

See [LICENSE](./LICENSE)
