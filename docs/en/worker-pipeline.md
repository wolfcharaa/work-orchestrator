# Work Orchestrator runtime protocol

[Русский](../ru/worker-pipeline.md)

## Ownership model

```text
root process
  Orchestrator
    ├─ owns the finite WorkQueue and admission barriers
    ├─ creates a bounded primary pool
    ├─ installs one Strategy copy in every primary
    ├─ grants WorkEnvelope values while draining live events
    ├─ aggregates results in admission order
    └─ cancels, finalizes, and stops the pool

primary worker (depth 1)
  WorkerRuntime
    ├─ optionally runs Strategy::boot()
    ├─ reuses process-local Strategy state and resources
    ├─ creates one structured ManagedTaskGroup per WorkEnvelope
    ├─ runs Strategy::run()
    ├─ joins every managed task
    └─ closes the child pool, then Strategy::close()

child worker (depth 2)
  ManagedTaskHandler
    └─ executes a small serializable ManagedTask message
```

Root coordinates without knowing payload semantics. Primary performs useful
strategy work. Child is optional isolation for strategy-owned subtasks. Managed
depth deliberately stops at child; a child cannot create another child pool.

## Why admission is separate from Strategy

`WorkQueue<TPayload>` stays in root and decides when the next `WorkEnvelope` may
be granted. Strategy receives already admitted work and never sorts the global
set. This separates two decisions:

- the queue owns ordering, strict barriers, and opportunistic fillers;
- Strategy owns payload processing, local recovery, and child tasks.

`acquire()` returns `Granted`, `Blocked`, or `Exhausted`. A grant carries a
unique `TaskLease`; only `complete()` for that lease clears its in-flight state.
When blocked, the worker awaits readiness instead of polling in a busy loop.

`OrderedWorkQueue` grants work in order without completion barriers.
`GroupedWorkQueue` does not open the next strict group until every item in the
current group finishes. Its opportunistic lane may occupy otherwise idle workers
while a barrier is pending, but cannot open that barrier.

## Identity and data

```php
new WorkEnvelope(
    new WorkId('region:50:AS_ADDR_OBJ'),
    new ImportTable(/* serializable recipe */),
);
```

`WorkEnvelope` intentionally carries only stable `WorkId` and application
payload. WorkId follows the task through lifecycle events, results, and
diagnostics. The payload is a control-plane recipe, not a bulk-data channel.
Keep XML or record batches process-local, or address them by path, key, or safe
range.

Strategy is serialized once per primary worker and each WorkEnvelope once per
assigned execution. This is not shared memory: every primary owns its Strategy
copy and mutable process-local resources.

## Strategy capabilities

The only mandatory contract is:

```php
interface Strategy
{
    public function run(
        WorkEnvelope $work,
        Cancellation $cancellation,
    ): mixed;
}
```

Optional behavior uses capability interfaces instead of a configuration god
object:

- `BootstrapsApplication` creates and closes process-local resources;
- `UsesManagedTasks` receives a fresh task-local group;
- `ProvidesTaskExecutionPolicy` chooses `Concurrent` or `Parallel` per managed
  task; unknown tasks default to child-process isolation;
- `ProvidesContainer` resolves handlers from a worker-local PSR-11 container;
- `ProvidesManagedTaskBootstrap` initializes child runtimes separately;
- `HandlesTaskFailure` returns bounded `RetryCurrent`, `CompleteAs`,
  `FailCurrent`, or `AbortRun` actions;
- `RoutesChildApplicationEvents` selects child events that may reach root.

`boot()` runs after the primary starts. `close()` must be idempotent and also
runs after partial bootstrap failure. Shutdown closes the child pool before
Strategy resources so child work cannot observe a closed connection or parser.

## Structured managed tasks

Before `run()`, a Strategy implementing `UsesManagedTasks` receives a new group.
`submit()` selects an executor through `TaskExecutionPolicy` and returns a
`TaskHandle` immediately. Concurrent tasks use bounded Fiber slots in primary;
parallel tasks use the reusable child pool.

The group belongs to one WorkEnvelope:

1. Strategy may submit multiple tasks and await individual handles.
2. Runtime always calls `join()` after `run()` returns.
3. If Strategy fails, the group is cancelled and still joined.
4. No new submit is accepted after join.
5. No managed task outlives its parent.

If Strategy processing and join or cleanup fail together,
`WorkerCleanupFailed` retains both the primary and cleanup failures.

## Events and correlation

Every primary and managed task has an `EventContext`: `runId`, `WorkId`, taskId,
parentTaskId, process role, depth, and sourceId. `ProcessEvents::emit()` is
available inside an active task scope and sends an `ApplicationEvent` to root.
Runtime adds PID, a source-local monotonic sequence, and timestamp.

Root drains the event channel while awaiting the result, preventing a full
channel from stalling the worker. Core lifecycle and application events have
separate observers. Observer failures do not replace task failures and are
retained by `observerFailures()`.

## Failure and crash recovery

An application failure leaves primary alive and may use `HandlesTaskFailure`.
A worker crash means the process was lost and is handled by root-local
`WorkerCrashPolicy`. Crash retry is allowed only if Strategy explicitly
implements `DeclaresIdempotentWork` and approves that WorkEnvelope.

`FailureDiagnostic` safely crosses IPC and retains:

- failure kind and type/message/code/file/line;
- origin: role, executor, PID, WorkId, taskId, and attempt;
- at most 5 trace frames and 3 previous causes;
- omitted frame/cause counts and message truncation state.

Arguments, local variables, payloads, environment values, and container graphs
are never captured. A full runtime death is outside core recovery: durable
dispatch and the decision to start a new run belong to the application or
operator.

## Cancellation and cleanup

External cancellation is combined with internal run shutdown. New admission
stops, active primary and child tasks receive cancellation, and root keeps
collecting available outcomes and diagnostics. Cooperative cleanup gets the
configured `cancellationGrace`; a stuck managed worker is then stopped forcibly.

Root hooks never cross a process boundary. Strategy, payloads, managed tasks,
events, and results must be serializable. Connections, streams, XMLReader
instances, generators, closures, Futures, and constructed containers remain
process-local.

## Resource upper bound

For `P` primary workers and `C` child workers per primary:

```text
up to 1 + P + P*C OS processes
```

Fiber concurrency `F` creates no extra processes but increases simultaneous
process-local operations. Select `P`, `C`, and `F` together with CPU, RAM,
descriptor, and disk/database throughput budgets. There is currently no dynamic
global child-worker budget shared by multiple primaries.
