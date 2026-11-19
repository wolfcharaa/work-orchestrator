# Public API reference

[Русский](../ru/public-api.md) · [README](../../README.en.md)

This reference groups components by consumer task. It explains component roles
and boundaries; exact signatures and generic types remain in source PHPDoc.
Start with [Getting started](getting-started.md) for the basic path.

## Composition, identity, and runs

| Component | Role and execution location | Normal use and constraint |
| --- | --- | --- |
| `Orchestrator` | One-shot root coordinator | Create with `fromQueue()`, configure before `run()`, receive `RunResult`; reuse/reconfiguration is rejected |
| `Strategy<TPayload,TResult>` | Application processing policy in primary | Constructor state is serializable; `run()` gets one `WorkEnvelope` and cancellation; the application owns I/O, transactions, and result meaning |
| `WorkEnvelope<TPayload>` | Serializable identity + payload pair | Crosses root to primary; payload must match Strategy and remain small |
| `WorkId` | Stable identity of one root item | Non-empty and unique in a queue; correlates hooks, events, results, diagnostics |

`Orchestrator` configuration before a run:

| Method | Default | Meaning |
| --- | ---: | --- |
| `primaryWorkers()` | 1 | Simultaneously active primary processes |
| `childWorkersPerPrimary()` | 1 | Child processes per primary for Parallel managed tasks |
| `concurrentTasksPerPrimary()` | 1 | Concurrent Fiber slots per primary |
| `maxIpcPayloadBytes()` | 1 MiB | Serialized control-message limit |
| `cancellationGrace()` | 5.0 s | Cooperative cleanup before force-stop |
| `workerCrashPolicy()` | abort | Root policy for primary loss; retry separately requires declared idempotency |

`beforeRun`, `onWorkerLifecycle`, `beforeTask`, `onTaskLifecycle`,
`onApplicationEvent`, `afterTask`, `afterRun`, and `onObserverFailure` register
the root-local hooks described below.

## Admission and queues

| Component | Role | Use / misuse consequence |
| --- | --- | --- |
| `WorkQueue<TPayload>` | Advanced root-local extension contract | Implement only for a new admission policy; queue is finite, count stable, leases complete exactly once |
| `OrderedWorkQueue` | Ready queue for independent items | Grants in order without a completion barrier; result order is stable, finish order is not |
| `GroupedWorkQueue` | Ready strict stages + opportunistic lane | Next group waits for current completion; never put dependent work in the opportunistic lane |
| `AdmissionDecision<T>` | Marker returned by `acquire()` | Consumer queue returns one of three concrete variants |
| `Granted<T>` | Permission to execute an item | Carries `TaskLease`; root sends its work to a worker |
| `Blocked` | Temporary admission stop | Carries awaitable readiness; never completing it deadlocks, an already-complete future creates a busy loop |
| `Exhausted` | Permanent exhaustion | Queue promises no further item |
| `TaskLease<T>` | Atomic grant: ID, result position, work | Queue accepts the same object in `complete()` once; a forged/equal-copy lease is invalid |
| `AdmissionPriority` | Strict priority or opportunistic value | Normally built with `strict(n)`/`opportunistic()` for file grouping |

`Scheduling\AbstractTaskQueue` is `@internal`, not a consumer base class. See
[Queues and barriers](work-queues.md) for the full public protocol and example.

## File adapter

| Component | Role and ownership | Normal use |
| --- | --- | --- |
| `FileWorkQueue` | Root-side `WorkQueue<FileEntry>` factory | `discover()` scans paths; `ordered()` accepts entries; grouped variants add barriers |
| `FileEntry` | Serializable canonical path + preflight size payload | Worker opens the path; size is a root snapshot, not a file lock |
| `FileOrder` | Root-local comparator before workers start | `pathAscending`, `largestFirst`, `smallestFirst`, `custom`; changes admission order, not barriers |
| `FilePriority` | Root-local strict/opportunistic classifier | `fromBasenamePatterns()` or `custom()`; conflicting strict priorities are rejected |
| `FileDiscoveryFailed` | Input metadata/discovery failure | Catch around composition when custom pre-run UX is needed |
| `InvalidReadRequest` | Invalid file input/pattern request | Caller fixes it; this is not a task outcome |

`File\Internal` types are not public extension points.

## Strategy capability interfaces

A strategy enables a capability by implementing its interface; there is no
separate registry.

| Interface | Caller / location | Consumer obligation |
| --- | --- | --- |
| `BootstrapsApplication` | Runtime in each primary: one `boot()`, `close()` at shutdown | Create process-local resources and make `close()` idempotent |
| `UsesManagedTasks` | Runtime before each root item | Retain the fresh task-local `ManagedTaskGroup` only for the current `run()` |
| `ProvidesTaskExecutionPolicy` | Primary before submit | Return a `TaskExecutionPolicy` choosing Concurrent/Parallel |
| `ProvidesContainer` | Primary handler resolver | Return a bootstrapped worker-local PSR-11 container; do not serialize one from root |
| `ProvidesManagedTaskBootstrap` | Child runtime | Return a serializable `ManagedTaskBootstrap` recipe for a child-local container |
| `ProvidesManagedTaskCrashPolicy` | Primary on child loss | Return bounded `ManagedTaskCrashPolicy`; retry requires `IdempotentManagedTask` |
| `HandlesTaskFailure` | Primary on application exception | Provide positive max attempts and one `FailureAction` |
| `DeclaresIdempotentWork` | Root before retry after primary loss | Explicitly approve the exact `WorkEnvelope`; a false claim may duplicate effects |
| `RoutesChildApplicationEvents` | Primary for child application events | Return `Relay` to root or local `Consume` |

Do not implement unused capabilities. Each optional contract adds lifecycle or
failure-handling obligations.

## Managed tasks

| Component | Role | Contract |
| --- | --- | --- |
| `ManagedTask` | Small serializable message | `handler()` returns a `ManagedTaskHandler` class-string |
| `ManagedTaskHandler` | Process-local executor for one message | `handle()` observes cancellation and returns a serializable value |
| `ManagedTaskGroup` | Structured task-local owner | `submit()` returns a handle; `join()` waits for all work and seals the group |
| `TaskHandle` | Awaitable for one submit | `outcome()` returns ledger; `value()` throws `ManagedTaskFailed` on failure |
| `TaskExecutionPolicy` | Executor choice per task | `modeFor()` returns `ExecutionMode` |
| `ExecutionMode::Concurrent` | Fiber in primary | Non-blocking I/O that may safely share primary-local services |
| `ExecutionMode::Parallel` | Child OS process | Default; CPU/blocking work or process isolation |
| `ManagedTaskBootstrap` | Child-local service graph lifecycle | `boot()` returns PSR-11 container; `close()` releases resources |
| `ManagedTaskCrashPolicy` | Child-loss recovery | `maxAttempts()` + `onCrash()` → `WorkerCrashAction` |
| `IdempotentManagedTask` | Safe-crash-retry marker | Implement only when repeat after an unknown failure point is safe |
| `ChildEventRoute` | Primary decision for a child event | `Relay` reaches root; `Consume` stops routing |

## Failure policy

| Component | Meaning and consequence |
| --- | --- |
| `FailureAction` | Marker returned by `HandlesTaskFailure::onFailure()` |
| `RetryCurrent` | Repeats the current item in a live primary after optional non-negative delay |
| `CompleteAs` | Marks the item successful with a value; use only for intentional fallback |
| `FailCurrent` | Ends the item unsuccessfully without another retry |
| `AbortRun` | Ends the item and initiates whole-run shutdown |
| `WorkerCrashPolicy` | Root-local primary-loss policy: max attempts and crash action |
| `WorkerCrashAction::AbortRun` | Does not repeat work in a new process; default |
| `WorkerCrashAction::RetryOnFreshWorker` | Uses a fresh primary only with `DeclaresIdempotentWork=true` |

Application failure and process crash are distinct signals. Crash retry does
not replace transactions or durable deduplication.

## Hooks

| Value / interface | Observer data |
| --- | --- |
| `RunContext` | Run ID, task count, primary limit, startedAt |
| `RunSummary` | Status, results, diagnostics, timestamps, `durationSeconds()` |
| `TaskBoundary<T>` | Run/task IDs, `WorkEnvelope`, admission position, optional terminal outcome |
| `WorkerLifecycle` | Shared `workerId()` for start/stop variants |
| `WorkerStarted` | Worker appeared |
| `WorkerStopped` | Worker stopped; `clean` distinguishes normal shutdown |
| `ObserverFailure` | Observer phase and original root-local `Throwable` |

These values remain in root and need not be serializable.

## Events

| Component | Role and use |
| --- | --- |
| `ApplicationEvent` | Marker for a small serializable application event |
| `ProcessEvents` | Consumer calls only `emit(ApplicationEvent)` in an active task; other lifecycle methods are `@internal` |
| `EventEnvelope` | Correlation + `CoreEvent|ApplicationEvent`; `isTaskLifecycle()` identifies core lifecycle |
| `EventContext` | Runtime correlation value; normally read through envelope, not created by consumers |
| `CoreEvent` | Runtime event marker |
| `TaskLifecycleEvent` | Core attempt-event marker |
| `TaskStarted` | Executor and started attempt number |
| `TaskSucceeded` | Executor and successful attempt number |
| `TaskFailed` | Executor, attempt, failure type/message |
| `RetryScheduled` | Executor, next attempt, delay |
| `ProcessRole` | `Primary` or `Child`, where the event originated |
| `ExecutorType` | `Primary`, `Concurrent`, or `Parallel`, which executor ran the task |

## Results and diagnostics

| Component | Role | Reading it |
| --- | --- | --- |
| `RunResult<TResult>` | Complete run result | Inspect `status`, results, diagnostics; `throwIfFailed()` is optional |
| `WorkResult<TResult>` | One admitted `WorkId` result | `outcome` does not throw; `value()` throws `WorkFailed` on failure |
| `TaskOutcome<TResult>` | Terminal status/value/failure plus prior diagnostics | Build with `succeeded/failed/cancelled`; queue receives it in `complete()` |
| `RunStatus` | `Succeeded`, `Failed`, `Cancelled` | Whole-run status, not individual-item status |
| `TaskStatus` | `Succeeded`, `Failed`, `Cancelled` | One root/managed item status |
| `FailureDiagnostic` | Bounded serializable failure evidence | Inspect `kind`, `origin`, type/message, truncation counters |
| `FailureKind` | Stable category | Application, Cancellation, WorkerCrash, Bootstrap, Cleanup, Transport, Observer, ForcedTermination |
| `FailureOrigin` | Typed coordinates | role/executor/PID/WorkId/task/attempt; PID 0 means unavailable process |
| `FailureCause` | Bounded prior-exception snapshot | Support value inside diagnostic, not an exception to catch |
| `TraceFrame` | Safe structural trace frame | Contains no arguments or locals |

## Exceptions

Most operational failures enter the ledger. Exceptions also cover composition
errors and explicit value/exception flows:

| Exception | When it appears / consumer action |
| --- | --- |
| `InvalidRunRequest` | Empty/duplicate queue, invalid limit, reused orchestrator/lease; fix composition |
| `InvalidManagedTask` | Handler cannot be created/resolved or has wrong type; fix task wiring |
| `IpcPayloadExceeded` | Serialized control message exceeds limit; shrink it |
| `ProcessEventScopeUnavailable` | `ProcessEvents::emit()` outside Strategy/handler scope; move it into active work |
| `WorkFailed` | `WorkResult::value()` requested for failure; inspect `$diagnostic` |
| `RunFailed` | `throwIfFailed()` called on failed/cancelled run; inspect `$result` |
| `ManagedTaskFailed` | `TaskHandle::value()` requested for failure; inspect diagnostics |
| `ManagedTaskGroupFailed` | Group join found several failures; inspect `failures` and `diagnostics` |
| `WorkerBootstrapFailed` | Primary creation or Strategy boot failed; use diagnostic/previous cause |
| `WorkerCleanupFailed` | Cleanup failed; retains primary failure and cleanup-failure list |
| `RemoteTaskFailure` | Runtime support exception reconstructing a remote cancellation cause; not an extension point |
| `FileDiscoveryFailed` / `InvalidReadRequest` | File composition could not build a manifest; fix inputs/access/patterns before run |

## Not public contract

All `Internal` and `File\Internal` namespaces, `ProcessEvents` methods marked
`@internal`, and `Scheduling\AbstractTaskQueue` may change without consumer
compatibility. Record an API gap rather than depending on an internal type.
