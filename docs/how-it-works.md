# How it works

The technical details behind the [README](../README.md). You don't need them to get started. For the reasoning behind these choices, see [decisions.md](./decisions.md).

## The status of a subscription

| Status | Meaning | Handled by the worker? |
|---|---|---|
| `NEW` | Discovered, but the setup hasn't run yet | No, it waits for `setup()` |
| `BOOTING` | Set up, or reset, and processing the events from the past | Yes |
| `ACTIVE` | Has reached the end of the events once, and keeps up with new ones | Yes |
| `ERROR` | A subscriber failed | No, it needs a `reset()` |
| `RESETTING` | A reset function is running | No |
| `DETACHED` | A worker found a subscription without a subscriber. Either it was removed, or the worker runs old code and doesn't know it yet | No, it needs a `reset()` |

## The loop

Each cycle of a worker:

1. Claims a free `BOOTING` subscription and processes a batch of up to `limit` events in one transaction
2. Does the same with a free `ACTIVE` subscription. Alternating keeps a long boot from starving active subscriptions, and the reverse
3. Decides what's next:
   - Events are pending: Continue right away
   - Everything is locked by other workers: Check again after 100 to 500 ms
   - Nothing to do: Wait for up to `maxIdleWait` seconds

The worker never calls `setup()` or `reset()`: Concurrent setups can fail, so `setup()` is a deploy step, and `NEW` subscriptions wait for it.

`run()` returns a `StopReason` if the worker was asked to stop or reached a limit. Exit with code 0 and let the process manager restart the worker. It throws `WorkerFailed` after 5 minutes without a successful cycle, and an uncaught exception results in a non-zero exit code.

## Waiting for events

`SleepWaiter` polls at the rate of `maxIdleWait`, so lower that option when using it. `CallbackWaiter` connects anything else that can wait, for example the waiter of [wwwision/dcb-eventstore-doctrine](https://github.com/bwaidelich/dcb-eventstore-doctrine). It checks the head of the event store every second, which is cheaper than a cycle of the worker:

```php
$waiter = new CallbackWaiter($eventStore->createWaiter($dedicatedConnection)->waitForNewEvents(...));
```

The waiter needs a connection of its own.

The worker tries to claim after each wait, however it ended. While events are pending it calls `wait(0.0)` after each batch, so that a waiter that receives notifications can process them in the meantime.

## What is guaranteed

**Exactly once** only applies to handlers that write through the connection of the subscription store: Their changes are part of the transaction of the catch-up. Handlers that write anywhere else get **at-least-once**, as a batch is repeated if a worker is killed or a run fails.

Events of a subscription are handled in order. There is no order between subscribers, so a handler must not rely on the projection of another one.

## Failures

| What | Reaction |
|---|---|
| A subscriber throws, and the transaction is intact | The subscription goes to `ERROR` and is **never retried**: The event may be partially applied. The worker logs a warning at startup and every 5 minutes, recovery takes a `reset()` |
| A run fails after the claim, for example with any SQL error of a handler on PostgreSQL | Rolled back, the status stays. `recover` is called, the subscription backs off for 1 to 60 seconds and the others continue. This repeats forever if the error does, only the logs show it |
| The database or the waiter fails | `recover` is called, the worker backs off for 1 to 30 seconds and tries again. After 5 minutes `run()` throws `WorkerFailed` |
| A PHP `Error` | Escapes right away, it is a bug |

The backoff is kept per process, so N workers retry independently.

A worker that hangs on a half-open connection can't detect that on its own: Enable [TCP keepalives](https://www.postgresql.org/docs/current/libpq-connect.html) on the connection and use `onHeartbeat` for a [liveness probe](https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/), as `examples/worker.php` and `examples/kubernetes/worker-deployment.yaml` do.

## Stopping

SIGTERM and SIGINT finish the running batch and end a wait early. Configure a grace period above your worst-case batch, `limit` × the duration of your handler. If the process gets killed anyway the batch is rolled back and repeated by another worker, so a second signal does nothing special.

Signals have to reach PHP directly: Use the exec form in containers, without a shell around it.

## Scaling

A subscription runs on one worker at a time, so **workers beyond the number of subscriptions add no throughput**. They only find locked rows, and check again every 100 to 500 ms while the others are busy.

### Automatic scaling

[KEDA](https://keda.sh) runs a query and sets `replicas = result / targetQueryValue`. With a `targetQueryValue` of `1`, the query from the [README](../README.md#scale-automatically-on-kubernetes-with-keda) returns the number of workers that can do work, which is the number of subscriptions that are behind.

- A sum of events would start the maximum number of workers for a single lagging subscription
- The threshold of 100 ignores normal lag
- `ERROR` is left out because more workers don't fix it
- `minReplicaCount: 1`, because with 0 workers new events wait for the next check of KEDA
- `maxReplicaCount` should be no higher than the number of subscriptions

## Logging

Passing a logger logs the start, the stop reason, backoffs and recoveries, and all engine events with their level. `SubscriberHandledEvent` is logged at `info`, which is one line per event and subscription.
