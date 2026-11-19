# Work Orchestrator

[Русский](README.md)

`fedor/work-orchestrator` runs a finite set of application tasks across multiple
OS processes and returns one run result. The library controls task admission,
parallelism limits, cancellation, events, and worker shutdown. The application
still owns task meaning, database and file access, transactions, idempotency,
and durable state.

Use it when the work set can be formed before a run and must be distributed
across a bounded number of processes. It is not a message broker, a background
job scheduler, or checkpoint/resume after the whole application stops.

## Minimal model

- the **root process** is the original application process; it owns the queue,
  decides what may start, and collects events and results;
- a **primary worker** is a depth-one child process that runs the application
  `Strategy` and reuses resources created inside that process;
- a **child worker** is an optional depth-two process for a managed strategy
  subtask;
- **admission** is the queue decision to grant work now, wait for a condition,
  or report that no work remains;
- the **payload** is a small serializable worker message, normally an ID, path,
  key, or range;
- **IPC (inter-process communication)** is the channel carrying tasks, events,
  and results;
- **cancellation** signals that new admission must stop and active work should
  shut down cooperatively.

See [Getting started](docs/en/getting-started.md) for complete definitions and
the first-run model.

## Installation and first result

PHP 8.4+ and Composer are required:

```bash
composer require fedor/work-orchestrator:^0.4
```

From a repository checkout, run the reproducible first scenario:

```bash
composer example:basic
```

Expected output:

```text
customer:42 => 42
customer:77 => 77
run => succeeded
```

The scenario creates two stable `WorkId` values, admits them through
`OrderedWorkQueue`, runs `RebuildCustomerStrategy` in two primary workers, and
prints results in queue order. Its payload and strategy classes live under
[`examples/Basic`](examples/Basic), so Composer can load them in newly created
worker processes. In an application, these classes must live in the
application's own PSR-4 namespace; defining them only in the entry script is not
sufficient.

## AI integration skill

The package ships a `work-orchestrator` skill for coding agents. In a project
that uses [`llm/skills`](https://github.com/roxblnfk/skills), install the plugin
as a development dependency and sync the skill from the installed library:

```bash
composer require --dev llm/skills
composer skills:update fedor/work-orchestrator
```

Then invoke `$work-orchestrator`. Before selecting classes or writing code, the
skill interactively resolves the finite work set, observable result, identity
and payload, ordering/barriers, process-local resources, subtasks,
retry/idempotency, cancellation, and the owner of durable recovery. It then
presents one Integration Contract for confirmation.

The skill source is
[`resources/skills/work-orchestrator`](resources/skills/work-orchestrator/SKILL.md).

## Where to go next

| Goal | Guide |
| --- | --- |
| Understand serialization, `WorkId`, results, and first-run constraints | [Getting started](docs/en/getting-started.md) |
| Choose an ordered, grouped, file, or custom queue | [Queues, barriers, and admission extensions](docs/en/work-queues.md) |
| Create process-local resources or split work across Fibers/child workers | [Managed tasks](docs/en/managed-tasks.md) |
| Connect hooks, events, diagnostics, and recovery | [Observability and recovery](docs/en/observability-and-recovery.md) |
| Find the role of a public class or interface | [Public API reference](docs/en/public-api.md) |
| Study the root → primary → child protocol | [Runtime protocol](docs/en/worker-pipeline.md) |
| Run a complete NDJSON parse-normalize-write flow | [Record import example](docs/en/record-import-example.md) |

## Guarantees and boundaries

`Orchestrator` is one-shot: after `run()` starts it cannot be reconfigured or
run again. Its `RunResult` retains successful and unsuccessful outcomes for
admitted work, bounded diagnostics, and observer failures.

Core does not parse XML/CSV/JSON, open transactions, persist an inbox/outbox, or
choose business ordering. Do not move bulk data through IPC; give a worker a
recipe for opening the resource locally. If the whole runtime dies, the
application or operator owns restart decisions and protection against duplicate
effects.

## Migrating from `fedor/async-parser`

The `0.4` line is a breaking rename without compatibility aliases. Package and
namespace become `fedor/work-orchestrator` and `Fedor\WorkOrchestrator`;
`AsyncParser::fromFiles()` becomes explicit
`Orchestrator::fromQueue(FileWorkQueue::discover(...))` composition; and a
`Strategy` plus optional capability interfaces replaces the file-specific
handler registry. Update the constraint, imports, and composition as one
cohesive change.

See [CHANGELOG.en.md](CHANGELOG.en.md) for release changes.
