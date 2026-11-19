# Queues, barriers, and admission extensions

[Русский](../ru/work-queues.md) · [README](../../README.en.md)

A `WorkQueue` does not execute work. It owns **admission**: when the next
`WorkEnvelope` may be sent to a primary worker. The queue lives only in root, so
it may hold root-local state, but it must be finite and must not perform the
payload's business processing.

## Choose a queue

| Requirement | Use | Guarantee |
| --- | --- | --- |
| Every item is independent | `OrderedWorkQueue` | Grants in supplied order without waiting for prior completion |
| Work has sequential stages | `GroupedWorkQueue` | The next strict group stays closed until the current group completes |
| Work items are discovered files | `FileWorkQueue::discover()` | Root builds one canonical file manifest and an ordinary ordered queue |
| Files need stages and fillers | `FileWorkQueue::discoverGrouped()` | File priority becomes strict groups plus an opportunistic lane |
| Admission needs another protocol | custom `WorkQueue` | The application owns all state, leases, and readiness |

Do not use a barrier merely to preserve grant order. `OrderedWorkQueue` may
grant the second item before the first completes, keeping workers useful.

## Strict barriers

A strict group is one work stage. Its items may run in parallel, but the next
group remains closed until **every** current-group item finishes:

```php
$queue = new GroupedWorkQueue([
    [$loadRegions, $loadTypes], // stage 0
    [$loadAddresses],           // stage 1 depends on all of stage 0
]);
```

With three workers, admission looks like this:

| Moment | Worker 1 | Worker 2 | Worker 3 | Stage 1 |
| --- | --- | --- | --- | --- |
| start | `loadRegions` | `loadTypes` | waits | closed |
| one stage-0 item completes | free | still works | waits | closed |
| all stage 0 completes | free | free | — | `loadAddresses` is eligible |

Use a barrier for a real data dependency, such as addresses that may be read
only after all dictionaries load. It is not an insertion-order mechanism.

## Opportunistic work: using otherwise idle workers

An opportunistic item does not belong to a sequential stage and cannot open the
next barrier. While the final long strict item is still running, independent
work may occupy an available worker:

```php
$queue = new GroupedWorkQueue(
    groups: [[$largeA, $largeB], [$dependent]],
    opportunistic: [$smallIndependentDictionary],
);
```

Use this lane only when the item:

- does not read partially completed strict-stage data;
- is not a prerequisite for opening the next stage;
- is safe to execute at any time in the current run.

The two constructor lists are the control surface: `groups` defines strict
barriers and `opportunistic` is the independent reserve. There is no dynamic
run-time reprioritization. If these properties are hard to prove, keep the item
in an explicit strict group.

## File adapter

`FileWorkQueue` is a ready queue factory, not a separate runtime. Root discovers
files once, resolves canonical path and size, and creates
`WorkEnvelope<FileEntry>` values:

```php
$queue = FileWorkQueue::discover(
    ['/data/incoming', '/data/manual.ndjson'],
    patterns: ['*.ndjson', '/^part-\d+\.json$/i'],
    order: FileOrder::largestFirst(),
);
```

Patterns filter directory contents; an explicitly supplied file is always
included. Orders include `pathAscending()`, `largestFirst()`, `smallestFirst()`,
and `custom(Closure)`. Canonical path becomes `WorkId`; duplicates are rejected
before workers start.

For grouped discovery, `FilePriority::fromBasenamePatterns()` maps a basename to
`AdmissionPriority::strict($number)` or `AdmissionPriority::opportunistic()`.
Lower strict numbers open first. The payload remains an ordinary `FileEntry`;
parsing and writing belong to the strategy.

## Custom `WorkQueue` protocol

A custom queue fits a quota, external window, or root-local admission state
that groups cannot express. Its public contract has three methods:

1. `count()` returns a stable, positive total work count.
2. `acquire()` returns exactly one of:
   - `Granted(TaskLease)` when work has been issued;
   - `Blocked(Future)` when no work may issue now; complete the future when
     state changes, after which root calls `acquire()` again;
   - `Exhausted` when no new work will ever be available.
3. `complete($lease, $outcome)` accepts the **same `TaskLease` object** exactly
   once, removes in-flight state, and wakes blocked callers when appropriate.

Every `WorkId` must be unique, `TaskLease::position` must identify a unique
result position, and lease IDs must be unique in the queue. Never return
`Blocked` with an already-completed future; that creates a busy loop. Do not
assume `TaskOutcome` is successful: admission completion and business success
are different states.

`AbstractTaskQueue` is marked `@internal` and is not an extension base. The
complete public [`WindowedWorkQueue`](../../examples/CustomQueue/WindowedWorkQueue.php)
example validates duplicate IDs, exact lease identity, duplicate completion,
and rotating readiness itself. It caps granted-but-incomplete root work:

```bash
composer example:custom-queue
```

Expected output:

```text
customer:42 => 42
customer:77 => 77
run => succeeded
```

With `primaryWorkers(2)` and `maxInFlight: 1`, the second scheduler lane gets
`Blocked`; completing the first lease wakes it and permits the second item. The
example demonstrates the extension mechanics, but an application queue still
needs tests for forged/duplicate leases, cancellation while waiting, and
exhaustion.

## What to extend

- Implement `WorkQueue` when admission policy changes.
- Implement `Strategy` when processing an admitted payload changes.
- Use managed tasks when one admitted item splits into bounded subtasks; do not
  turn the root queue into a dynamic tree.
- Do not depend on `Internal` or `File\Internal` types; they are not compatible
  consumer extension points.

Related: [Runtime protocol](worker-pipeline.md), [Managed tasks](managed-tasks.md),
and [Public API reference](public-api.md).
