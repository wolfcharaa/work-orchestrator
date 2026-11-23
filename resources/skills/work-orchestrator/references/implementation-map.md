# Implementation Map

Use this map only after the Integration Contract is accepted. Resolve paths
against the installed package root (`vendor/fedor/work-orchestrator`) or the
library source checkout.

## Documentation Routes

| Accepted need | Read and inspect |
| --- | --- |
| First finite run, serialization, IDs, result access | `docs/en/getting-started.md` or `docs/ru/getting-started.md`; `examples/consumer` for a clean application; `examples/basic.php` for repository development |
| Ordered, grouped, file, or custom admission | `docs/en/work-queues.md` or `docs/ru/work-queues.md`; `examples/CustomQueue` |
| Primary-local resources and managed tasks | `docs/en/managed-tasks.md` or `docs/ru/managed-tasks.md`; `examples/ManagedTasks` |
| Hooks, worker events, results, failure/crash recovery | `docs/en/observability-and-recovery.md` or RU equivalent; `examples/Observability` |
| Meaning of a named public component | `docs/en/public-api.md` or `docs/ru/public-api.md`, then its `src/` declaration |
| Full root → primary → child lifecycle | `docs/en/worker-pipeline.md` or `docs/ru/worker-pipeline.md` |
| File parse-normalize-write ownership | `docs/en/record-import-example.md` or RU equivalent; `examples/RecordImport` |

Prefer the user's language. Inspect source after the guide whenever exact
signatures, defaults, generics, or version-specific behavior affect code.

## Component Selection

| Contract fact | Primary component choice |
| --- | --- |
| Independent finite list | `OrderedWorkQueue` |
| Hard stage dependencies | `GroupedWorkQueue` strict groups |
| Independent fillers while a strict stage runs | Opportunistic lane, only with proven independence |
| Files discovered/frozen in root | `FileWorkQueue` |
| Admission rule not expressible above | Public `WorkQueue` implementation with its own lease/readiness state |
| One item, one processing call | `Strategy` only |
| Reused DB/parser/container per primary | `BootstrapsApplication` |
| Async I/O subtasks sharing primary resources | `UsesManagedTasks` + `ExecutionMode::Concurrent` |
| CPU/blocking or isolated subtasks | `UsesManagedTasks` + `ExecutionMode::Parallel` |
| Handler dependency resolution | `ProvidesContainer` and/or `ProvidesManagedTaskBootstrap` |
| Retry while primary remains alive | `HandlesTaskFailure` |
| Retry after primary disappears | `WorkerCrashPolicy` + `DeclaresIdempotentWork` |
| Retry after child disappears | `ManagedTaskCrashPolicy` + `IdempotentManagedTask` |
| Worker-originated progress/facts | `ApplicationEvent` + `ProcessEvents::emit()` |
| Root metrics/logging/lifecycle | `Orchestrator` hooks |

## Invariants To Preserve

- The queue is finite and stays in root.
- Every `WorkId` is stable and unique within the run.
- Objects crossing IPC are Composer-autoloadable and serializable.
- Connections, streams, parsers, closures, Futures, and built containers remain
  process-local.
- A custom queue completes the exact granted `TaskLease` once and wakes blocked
  callers without polling.
- Managed depth stops at root → primary → child logically; every primary and
  managed worker remains a direct OS child owned and reaped by root.
- Crash retry requires explicit item-level idempotency because the old process
  may have completed a side effect.
- `RunResult` is a ledger; exception flow through `throwIfFailed()` is optional.
- The application owns durable intent, checkpoints, transactions,
  deduplication, and restart after total runtime loss.
- `Internal`, `File\\Internal`, and `Scheduling\\AbstractTaskQueue` are not
  consumer extension points.

## Verification Routes

Mirror the nearest repository test rather than inventing a same-process fake:

- generic work and admission: `tests/GenericWorkTest.php`,
  `tests/TaskAdmissionQueueTest.php`;
- lifecycle/failure policy: `tests/LifecycleAndFailurePolicyTest.php`;
- managed execution: `tests/ManagedTaskExecutionTest.php`;
- process events: `tests/ProcessEventsTest.php`;
- native framing, direct parentage, lazy managed lifecycle, and active-loop
  compatibility: `tests/NativeProcessRuntimeTest.php`;
- file adapter: `tests/FileWorkQueueTest.php`;
- complete examples: `tests/DocumentationExamplesTest.php` and
  `tests/RecordImportExampleTest.php`.
- package-maintainer clean dependency install and a real primary-worker
  boundary: `composer consumer:check` from the library source checkout.

When the consumer repository uses another test framework, preserve its
framework while retaining the same process-boundary evidence.
