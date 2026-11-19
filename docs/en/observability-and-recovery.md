# Observability, failures, and recovery

[Русский](../ru/observability-and-recovery.md) · [README](../../README.en.md)

The library separates root-local hooks, events from workers, and the final
ledger. Hooks fit metrics and external logging in the original process; events
carry correlated facts from executing work; `RunResult` is the source of truth
after the run ends.

## Reproducible lifecycle scenario

Run:

```bash
composer example:observability
```

Expected output for one item:

```text
run: start tasks=1
task: admit customer:42
lifecycle: customer:42 started attempt=1
application: rebuilt customer=42
lifecycle: customer:42 succeeded attempt=1
task: complete customer:42 status=succeeded
run: finish status=succeeded
```

[`observability.php`](../../examples/observability.php) registers root
observers. In primary,
[`ObservedCustomerStrategy`](../../examples/Observability/ObservedCustomerStrategy.php)
calls `ProcessEvents::emit()`, after which core adds correlation and delivers
[`CustomerRebuilt`](../../examples/Observability/CustomerRebuilt.php) to
`onApplicationEvent`. This crosses the real IPC boundary; it is not a manual
observer call in the same process.

## Root-local hooks

A hook closure is never serialized into a worker. It may therefore capture a
root-local logger or metrics client, but should not perform heavy work that
useful processing depends on.

| Method | Argument | Use |
| --- | --- | --- |
| `beforeRun` | `RunContext` | Run ID, task count, primary limit, start time |
| `onWorkerLifecycle` | `WorkerStarted` / `WorkerStopped` | Live-worker count and clean shutdown |
| `beforeTask` | `TaskBoundary` without outcome | Root-item admission and position |
| `onTaskLifecycle` | `EventEnvelope` with `TaskStarted`, `TaskSucceeded`, `TaskFailed`, `RetryScheduled` | Attempts for primary and managed tasks |
| `onApplicationEvent` | `EventEnvelope` with application `ApplicationEvent` | Progress and application facts |
| `afterTask` | `TaskBoundary` with `TaskOutcome` | Terminal admitted-root-item outcome |
| `afterRun` | `RunSummary` | Whole-run status, duration, results, diagnostics |
| `onObserverFailure` | `ObserverFailure` | Separate failure channel for other observers |

An observer failure does not replace a task outcome. It is retained by
`RunResult::observerFailures()` and sent to `onObserverFailure`. Keep
`onObserverFailure` especially small; it is the last defensive channel.

## Application events

An application event is a small serializable object implementing
`ApplicationEvent`. From a `Strategy`, handler, or nested service:

```php
ProcessEvents::emit(new RowsParsed($count));
```

Outside an active task scope this throws `ProcessEventScopeUnavailable`. Do not
send record batches or models: `maxIpcPayloadBytes()` bounds the event and the
channel is not a data plane.

`EventEnvelope` adds `runId`, `WorkId`, `taskId`, `parentTaskId`, process role,
depth, source ID, source-local monotonic sequence, timestamp, and PID. Compare
sequence only within one `sourceId`; no global order across workers is promised.

## Results without losing partial progress

`RunResult` is returned for `succeeded`, `failed`, and `cancelled`:

- `results()` gives terminal `WorkResult` values for admitted items in
  admission order;
- `values()` gives successful values only;
- `result(WorkId)` is nullable, non-throwing access;
- `value(WorkId)` returns a value or throws `WorkFailed` with the same diagnostic;
- `diagnostics()` retains task, retry, bootstrap, cleanup, and transport evidence;
- `observerFailures()` retains isolated root observer failures;
- `summary` is the final `RunSummary`;
- `throwIfFailed()` optionally throws `RunFailed`, retaining the original
  `RunResult` in `$exception->result`.

`TaskOutcome` contains `TaskStatus`, value, terminal failure, and diagnostics
from prior attempts. For a partially failed run, inspect outcomes before blindly
asking every `WorkResult` for `value()`.

## Diagnostic contents

`FailureDiagnostic` is a bounded serializable snapshot, not a remote
`Throwable`. It carries kind, type/message/code/file/line, origin coordinates
(role, executor, PID, `WorkId`, task ID, attempt), up to five trace frames and
three previous causes, plus truncation counters.

Arguments, locals, payloads, environment, and container graphs are deliberately
not captured. That makes diagnostics safer for IPC but does not replace
application logging with fields your system needs.

Kinds are `application`, `cancellation`, `worker_crash`, `bootstrap`, `cleanup`,
`transport`, `observer`, and `forced_termination`.

## Failure and recovery ownership matrix

| Event | Resulting state | Recovery owner |
| --- | --- | --- |
| `Strategy::run()` throws while primary lives | `HandlesTaskFailure` receives throwable and attempt | Strategy returns `RetryCurrent`, `CompleteAs`, `FailCurrent`, or `AbortRun` |
| Primary process disappears | `worker_crash` diagnostic; side-effect completion is unknown | Root `WorkerCrashPolicy`; retry also requires `DeclaresIdempotentWork` for that item |
| Managed handler throws | Unsuccessful managed `TaskOutcome` | Strategy reads the handle or lets group completion surface it; see managed tasks |
| Child process disappears | `worker_crash` diagnostic | `ManagedTaskCrashPolicy`; retry only for `IdempotentManagedTask` |
| Bootstrap or cleanup fails | Run/work receives bounded diagnostics; cleanup may retain primary cause | Application fixes lifecycle; no automatic new run is created |
| Observer throws | Useful work continues; failure is separate in `observerFailures()` | Root monitoring code |
| IPC message exceeds its limit | `transport` diagnostic before unsafe transport | Shrink payload/event/result and open bulk resources locally |
| External cancellation | New admission stops; active work receives cancellation; status is `cancelled` when cancellation caused shutdown | Caller and cooperative cleanup |
| Whole runtime dies | In-memory `RunResult` may not exist | Durable application state and operator; core does not resume |

## Two distinct retry paths

`HandlesTaskFailure` applies while primary is **alive** and strategy invocation
is known to have thrown. `maxAttempts()` bounds total attempts.
`RetryCurrent($delay)` repeats in that primary; `CompleteAs($value)` turns a
known failure into a successful value; `FailCurrent` ends only the item; and
`AbortRun` stops the run.

`WorkerCrashPolicy` applies when the process **disappeared** and side-effect
timing is unknown. The default aborts the run. `RetryOnFreshWorker` is accepted
only when `DeclaresIdempotentWork::isIdempotent($work)` returns `true`; otherwise
runtime rejects the unsafe retry.

Delays and retries exist only in memory for the current run. For durable retry
after restart, persist intent/checkpoint in the application and start a new run.

## Cancellation and cleanup

The signal passed to `run($strategy, $cancellation)` combines with internal run
shutdown. Strategies and handlers should call `throwIfRequested()` periodically
or pass cancellation into async operations. Root waits no longer than
`cancellationGrace()` (default 5 seconds) for cooperative cleanup, after which a
stuck worker may be force-stopped.

`close()` releases resources; it is not a place to begin substantial new work.
Keep cleanup bounded and idempotent, and design transactions or compensation
for partial side effects separately.

Related: [Managed tasks](managed-tasks.md), [Runtime protocol](worker-pipeline.md),
and [Public API reference](public-api.md).
