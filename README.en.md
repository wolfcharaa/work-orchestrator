# Work Orchestrator

[Русский](README.md)

`fedor/work-orchestrator` distributes finite application-owned work across OS
processes and manages their lifecycle. The library owns admission, root / primary
/ child limits, cancellation, events, diagnostics, and cleanup. Useful work
stays in the application `Strategy`.

Files are a ready-made adapter, not a core constraint. A payload may be a
command, DTO, durable range locator, or `FileEntry`.

## Installation

```bash
composer require fedor/work-orchestrator:^0.4
```

PHP 8.4+ is required. Objects crossing a process boundary must be serializable
in the Composer worker environment.

## Minimal run

```php
<?php

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;

final readonly class RebuildCustomer
{
    public function __construct(public int $customerId) {}
}

/** @implements Strategy<RebuildCustomer, int> */
final class RebuildCustomerStrategy implements Strategy
{
    /** @param WorkEnvelope<RebuildCustomer> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        $cancellation->throwIfRequested();

        return $work->payload->customerId;
    }
}

$queue = new OrderedWorkQueue([
    new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
    new WorkEnvelope(new WorkId('customer:77'), new RebuildCustomer(77)),
]);

$result = Orchestrator::fromQueue($queue)
    ->primaryWorkers(2)
    ->run(new RebuildCustomerStrategy());

$customerId = $result->value(new WorkId('customer:42'));
```

`WorkId` correlates admission, events, results, and failures. Keep bulk data out
of the payload; pass an identifier, path, key, or safe range through IPC.

An `Orchestrator` instance is one-shot. It cannot be reconfigured or run again
after `run()` starts.

## Files

`FileWorkQueue` discovers files once on the root side, resolves canonical paths,
captures sizes, and returns an ordinary `WorkQueue<FileEntry>`:

```php
use Fedor\WorkOrchestrator\File\FileOrder;
use Fedor\WorkOrchestrator\File\FileWorkQueue;

$queue = FileWorkQueue::discover(
    ['/data/incoming', '/data/manual.ndjson'],
    patterns: ['*.ndjson', '/^part-\d+\.json$/i'],
    order: FileOrder::largestFirst(),
);
```

Patterns apply to directory contents; explicitly supplied files are always
included. Orders include `pathAscending()`, `largestFirst()`, `smallestFirst()`,
and `custom(Closure)`. The canonical path becomes the `WorkId`; duplicates are
rejected before workers start. Workers do not reopen a file merely to validate
the root manifest.

See [`examples/record-import.php`](examples/record-import.php) for a complete
parse-normalize-write example.

## Barriers and work-conserving fillers

```php
use Fedor\WorkOrchestrator\AdmissionPriority;
use Fedor\WorkOrchestrator\File\FilePriority;

$queue = FileWorkQueue::discoverGrouped(
    ['/data/gar'],
    priority: FilePriority::fromBasenamePatterns(
        [
            0 => ['AS_ADDR_OBJ_*.XML'],
            10 => ['AS_HOUSES_*.XML'],
        ],
        unmatched: AdmissionPriority::opportunistic(),
    ),
    withinGroup: FileOrder::largestFirst(),
    patterns: ['*.XML'],
);
```

Every strict item in the open group must finish before the next group opens.
Opportunistic items may occupy workers that would otherwise be idle while that
strict group is in flight, but they never open the next barrier. This keeps
independent small dictionaries useful without violating dependency order.

For arbitrary payloads, use `OrderedWorkQueue` or `GroupedWorkQueue` directly,
or implement a finite root-local `WorkQueue`.

## Process-local bootstrap

Implement `BootstrapsApplication` when a strategy needs a database connection,
parser, or DI container. `boot()` runs once after each primary starts and
`close()` runs during shutdown. These resources are created inside the worker
and reused across its work items instead of being serialized from root.

`ProvidesContainer` and `ProvidesManagedTaskBootstrap` cover container-aware
child handlers. Logging can come from that worker-local container; core does
not require a separate logging contract.

## Managed child tasks

A strategy implementing `UsesManagedTasks` receives a fresh
`ManagedTaskGroup` for every root work item:

```php
$first = $tasks->submit(new ParseRange($path, 0, 1000));
$second = $tasks->submit(new ParseRange($path, 1000, 2000));
$tasks->join();

$rows = [...$first->value(), ...$second->value()];
```

A `ManagedTask` is a small serializable message whose `handler()` names a
`ManagedTaskHandler`. The group is structured: child work cannot outlive its
parent. Depth is capped at root → primary → child; a child cannot create another
pool. Configuration methods are:

- `primaryWorkers(n)` for active primary OS processes;
- `childWorkersPerPrimary(n)` for reusable child processes per primary;
- `concurrentTasksPerPrimary(n)` for Fiber concurrency inside a primary;
- `maxIpcPayloadBytes(n)` for serialized control-plane payloads;
- `cancellationGrace(seconds)` for cooperative cleanup time.

## Lifecycle and events

Root-local hooks are configured with `beforeRun`, `onWorkerLifecycle`,
`beforeTask`, `onTaskLifecycle`, `onApplicationEvent`, `afterTask`, `afterRun`,
and `onObserverFailure`. They observe the run but are not serialized into a
worker.

Application code may publish a small serializable event from a strategy,
handler, or deeper service:

```php
ProcessEvents::emit(new RowsParsed($count));
```

The envelope preserves `runId`, `WorkId`, task and parent task IDs, process
role, depth, PID, and sequence. Do not use the event channel for bulk data.

## Results, failures, and recovery

`RunResult` is a complete ledger available after success, partial failure, or
cancellation:

- `results()` returns every admitted `WorkResult`;
- `values()` returns successful values in admission order;
- `result(WorkId)` provides non-throwing keyed outcome access;
- `value(WorkId)` returns the value or throws typed `WorkFailed` with the same
  diagnostic;
- `diagnostics()` returns bounded structured failure evidence;
- `observerFailures()` retains root observer failures;
- `throwIfFailed()` optionally switches to exception flow.

A diagnostic carries failure kind and origin
(role/executor/PID/WorkId/task/attempt), at most 5 stack frames and 3 previous
causes, plus explicit truncation counters. Function arguments, payloads, local
variables, environment values, and container objects are never captured.

`HandlesTaskFailure` lets a strategy retry or finish its current work item
within a bounded attempt count. `WorkerCrashPolicy` handles a vanished primary
process separately. This library is not a durable dispatcher: after the whole
runtime dies, the application or operator decides whether to start a new run.

## Migrating from `fedor/async-parser`

The `0.4` line is a breaking rename without compatibility aliases:

- package and namespace become `fedor/work-orchestrator` and
  `Fedor\WorkOrchestrator`;
- `AsyncParser::fromFiles()` becomes explicit
  `Orchestrator::fromQueue(FileWorkQueue::discover(...))` composition;
- `FileTask`, the handler registry, and worker bootstrap collapse into one
  application `Strategy` plus optional capability interfaces;
- a file path is no longer core identity: a generic queue carries
  `WorkEnvelope(WorkId, payload)` and results are read through `WorkResult`.

Legacy classes and the old facade are intentionally not retained. Update the
package constraint, namespace imports, and queue composition as one cohesive
application change.

## Responsibility boundary

Core does not parse XML/CSV/JSON, provide checkpoint/resume, persist an
inbox/outbox, or decide business ordering. It provides finite admission,
bounded process depth, cancellation, cleanup, observability, and an honest
result. Data formats, transactions, idempotency, and retry semantics belong to
the strategy and application.

See [docs/en/worker-pipeline.md](docs/en/worker-pipeline.md) for the process
protocol.

See [CHANGELOG.en.md](CHANGELOG.en.md) for release changes.
