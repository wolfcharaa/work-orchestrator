# Process-local resources and managed tasks

[Русский](../ru/managed-tasks.md) · [README](../../README.en.md)

This page answers two separate questions: how one primary worker reuses an
expensive resource, and how one root work item safely splits into bounded
subtasks. A managed task does not add another root item and cannot outlive its
parent `Strategy::run()`.

## When one Strategy is enough

Start with an ordinary `Strategy`. If one call can process the payload and no
independent subtasks exist, managed tasks are unnecessary. `primaryWorkers(n)`
already processes up to `n` root items at once in separate processes.

When the strategy needs a parser, database connection, or DI container,
implement `BootstrapsApplication`:

```php
final class ImportStrategy implements BootstrapsApplication
{
    private ?DatabaseConnection $connection = null;

    public function boot(Cancellation $cancellation): void
    {
        $this->connection = DatabaseConnection::connect(/* serializable config */);
    }

    public function run(WorkEnvelope $work, Cancellation $cancellation): mixed
    {
        $connection = $this->connection
            ?? throw new LogicException('Strategy is not bootstrapped.');

        // use the process-local connection
    }

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null; // makes repeated close safe
    }
}
```

The strategy constructor carries only a serializable connection recipe.
`boot()` runs once after each primary starts, `run()` runs for every assigned
item, and `close()` runs during shutdown, including after a partial boot failure.
Therefore `close()` must be idempotent.

## Reproducible managed-task example

The example squares `1, 2, 3, 4` in child workers and sums the values in primary:

```bash
composer example:managed-tasks
```

Expected output:

```text
numbers => 1, 2, 3, 4
sum of squares => 30
run => succeeded
```

Code is separated by responsibility:

- [`NumberBatch`](../../examples/ManagedTasks/NumberBatch.php) is one root
  work-item payload;
- [`SquareNumber`](../../examples/ManagedTasks/SquareNumber.php) is the small
  serializable subtask message and handler name;
- [`SquareNumberHandler`](../../examples/ManagedTasks/SquareNumberHandler.php)
  performs useful work in the selected executor;
- [`SumSquaresStrategy`](../../examples/ManagedTasks/SumSquaresStrategy.php)
  receives the task-local group, submits work, and collects values;
- [`managed-tasks.php`](../../examples/managed-tasks.php) owns root composition
  and limits.

This is one scenario rather than disconnected API snippets: Composer loads the
`examples/` classes across the actual process boundary.

## Group lifecycle

A strategy enables `UsesManagedTasks`. Before every `run()`, runtime passes a
new `ManagedTaskGroup` through `useManagedTasks()`:

```php
$first = $tasks->submit(new SquareNumber(3));
$second = $tasks->submit(new SquareNumber(4));

$sum = $first->value($cancellation) + $second->value($cancellation);
```

`submit()` immediately returns `TaskHandle`. `value()` awaits the outcome and
throws `ManagedTaskFailed` for unsuccessful work; `outcome()` lets the strategy
inspect `TaskOutcome` instead. Explicit `join()` is useful for side-effect-only
work, but runtime always joins every task after `run()` returns. New submits are
forbidden after join.

If strategy processing fails, the group is cancelled and still joined. Root
does not begin process finalization until managed work has settled. Primary and
managed process-local close hooks are independent, so consumers must not rely
on an ordering between them.

## Concurrent or Parallel

`ProvidesTaskExecutionPolicy` returns a `TaskExecutionPolicy` that chooses a
mode for each `ManagedTask`:

| `ExecutionMode` | Runs in | Choose for | Main risk |
| --- | --- | --- | --- |
| `Concurrent` | Fiber in the same primary | Async I/O that may safely share a process-local container | CPU-bound or blocking code stalls the whole primary |
| `Parallel` | Reusable child OS process | CPU/blocking work or process isolation | IPC and separate resource initialization |

An unknown task defaults to `Parallel`, the stronger isolation. Per primary,
`concurrentTasksPerPrimary(n)` bounds Fiber slots and
`childWorkersPerPrimary(n)` bounds a lazy managed pool. Managed workers are
logical depth-two workers but direct OS children of root, which owns their
termination and reaping. With `P` primaries and `C` children, the upper bound is
`1 + P + P*C` processes.

## How a handler is created

`ManagedTask::handler()` returns a `ManagedTaskHandler` class-string. Runtime:

1. asks the worker-local PSR-11 container when the strategy exposes one through
   `ProvidesContainer` (Concurrent);
2. asks the container created by `ManagedTaskBootstrap` (Parallel);
3. otherwise constructs a handler with a public no-required-argument
   constructor.

`ProvidesManagedTaskBootstrap` returns a serializable recipe. Its `boot()` runs
inside each child and returns a container; `close()` releases child-local
resources. Do not pass an already-built primary container into a child.

## Failure and child-process loss

A normal handler exception becomes an unsuccessful `TaskOutcome`;
`TaskHandle::value()` then throws `ManagedTaskFailed`. Losing a child process is
a different boundary: retry is selected through
`ProvidesManagedTaskCrashPolicy`, but the task must implement the
`IdempotentManagedTask` marker. Without that explicit assertion runtime does not
repeat work after process loss because the old child may already have produced
a side effect.

`RoutesChildApplicationEvents` lets primary inspect a child application event
and return `ChildEventRoute::Consume` or `Relay`. `Relay` forwards the envelope
to the root observer; `Consume` keeps the decision in primary.

## Limits you control

| `Orchestrator` method | Default | Meaning |
| --- | ---: | --- |
| `primaryWorkers(n)` | 1 | Simultaneously active primary processes |
| `childWorkersPerPrimary(n)` | 1 | Lazy reusable managed workers associated with each primary |
| `concurrentTasksPerPrimary(n)` | 1 | Fiber slots per primary |
| `maxIpcPayloadBytes(n)` | 1 MiB | Serialized task/event/result control-message bound |
| `cancellationGrace(seconds)` | 5.0 | Cooperative cleanup time before force-stop |
| `forceKillAfter(seconds)` | 2.0 | Time after TERM before KILL |
| `workerOutputTailBytes(bytes)` | 16 KiB | Retained stdout/stderr tail per worker |
| `workerAutoloader(path)` | auto | Composer autoloader override for clean workers |

Choose limits against CPU, RAM, file descriptors, and database/disk throughput.
There is no global child budget shared across primaries.

Related: [Runtime protocol](worker-pipeline.md),
[Observability and recovery](observability-and-recovery.md), and
[Public API reference](public-api.md).
