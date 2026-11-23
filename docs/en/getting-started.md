# Getting started

[Русский](../ru/getting-started.md) · [README](../../README.en.md)

After this guide you can run two finite tasks, observe their result, and know
which objects stay in root versus which Composer must load in a worker.

## Definitions

| Term | Meaning in this library |
| --- | --- |
| finite set | The queue knows its total task count; it does not wait for new messages after exhaustion |
| root | The process that calls `Orchestrator::run()`; the queue and hooks live only here |
| primary worker | A depth-one process with its own `Strategy` copy |
| child worker | A logical depth-two worker for a managed `ManagedTask`; root spawns it directly and the library creates no deeper level |
| admission | The `WorkQueue::acquire()` decision: grant work, wait temporarily, or report exhaustion |
| payload | The application object inside `WorkEnvelope`; it is a work recipe, not a bulk-data batch |
| lease | The unique `TaskLease` connecting one grant to exactly one completion |
| IPC | Inter-process communication carrying serialized tasks, events, and results |
| process-local | Created and used in one process; a connection, stream, or parser does not cross IPC |
| cancellation | A signal to stop new admission and let active work shut down cooperatively |
| idempotency | Repeating the same work does not create an unacceptable duplicate effect |

PHP class and method names remain exact API identifiers.

## First observable result

Start in a clean application directory. These commands install the released
package, add the application's PSR-4 namespace, and copy the minimal payload,
strategy, and entry point from the installed package:

```bash
mkdir work-orchestrator-quick-start
cd work-orchestrator-quick-start
composer init --name=app/work-orchestrator-quick-start --license=proprietary --autoload=src/ --no-interaction
composer require fedor/work-orchestrator:^1.0
cp -R vendor/fedor/work-orchestrator/examples/consumer/src .
cp -R vendor/fedor/work-orchestrator/examples/consumer/bin .
composer dump-autoload
php bin/rebuild-customers.php
```

The inputs are fixed in
[`bin/rebuild-customers.php`](../../examples/consumer/bin/rebuild-customers.php):
customers `42` and `77`. Expected output:

```text
customer:42 => 42
customer:77 => 77
run => succeeded
```

What happened:

1. `OrderedWorkQueue` retained two tasks in supplied order.
2. Each `WorkEnvelope` connected a stable `WorkId` to a small
   `RebuildCustomer` payload.
3. Root created at most two primary workers and sent serializable work to them.
4. `RebuildCustomerStrategy::run()` checked cancellation and returned the ID.
5. `RunResult::results()` returned outcomes in admission order even if workers
   finished in another order.

Useful work is in
[`App\WorkOrchestratorQuickStart\Work\RebuildCustomerStrategy`](../../examples/consumer/src/Work/RebuildCustomerStrategy.php),
while composition is in the entry point. The fixture has
[its own Composer/autoload contract](../../examples/consumer/composer.json) and
does not depend on the library's `autoload-dev`. The strategy comment marks
where an application would load its model and perform the action; core does not
do that.

When developing the library itself, the same result can be checked with a
repository shortcut:

```bash
composer install
composer example:basic
```

That shortcut uses repository classes under `examples/Basic`; an external
consumer does not inherit dependency scripts or `autoload-dev`, so its primary
path is the fixture above.

## Moving it into an application

Every class crossing a process boundary must be available through Composer
autoload. A typical application layout is:

```text
src/
  Work/RebuildCustomer.php
  Work/RebuildCustomerStrategy.php
bin/
  rebuild-customers.php
```

With the usual `"App\\": "src/"` mapping, the classes use the `App\Work`
namespace. Refresh the autoloader after adding files (`composer dump-autoload`).
Do not define a payload or `Strategy` only inside `bin/*.php`: a new PHP process
loads the Composer autoloader; it does not execute the parent's entry script.

Root composition stays small:

```php
$queue = new OrderedWorkQueue([
    new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
    new WorkEnvelope(new WorkId('customer:77'), new RebuildCustomer(77)),
]);

$result = Orchestrator::fromQueue($queue)
    ->primaryWorkers(2)
    ->run(new RebuildCustomerStrategy());
```

A `WorkId` must be unique within its queue and stably identify the work. Hooks,
events, diagnostics, and keyed result lookup all carry it.

## What may be serialized

IPC carries the `Strategy`, each `WorkEnvelope` and payload, managed tasks,
application events, and results. Put simple values and DTOs in them: IDs, paths,
keys, query parameters, and safe ranges.

Do not carry open connections, streams, `XMLReader`, generators, closures,
`Future`, or a built DI container. Create them in primary through
`BootstrapsApplication`; see [Managed tasks](managed-tasks.md). The default
`maxIpcPayloadBytes()` is 1 MiB and applies to control-plane messages, not only
the application payload.

## Reading the result

`run()` normally returns a ledger instead of throwing for every failure:

```php
if ($result->status === RunStatus::Succeeded) {
    $customerId = $result->value(new WorkId('customer:42'));
}

foreach ($result->results() as $workResult) {
    $outcome = $workResult->outcome; // status, value, failure, diagnostics
}
```

- `values()` returns only successful values in admission order;
- `result($id)` returns `null` for an unknown ID and does not throw;
- `value($id)` throws `WorkFailed` for an unsuccessful outcome and
  `OutOfBoundsException` for an unknown ID;
- `throwIfFailed()` switches the whole run to exception flow and throws
  `RunFailed` unless the status is `succeeded`.

For partial failures, inspect the ledger and diagnostics first. The full
behavior matrix is in [Observability and recovery](observability-and-recovery.md).

## Choose the next path

- For a simple list, dependent stages, or files, see
  [Queues and barriers](work-queues.md).
- When one root item must create several bounded subtasks, see
  [Managed tasks](managed-tasks.md).
- For progress events, hooks, or failure handling, see
  [Observability and recovery](observability-and-recovery.md).
