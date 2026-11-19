---
name: work-orchestrator
description: Integrate fedor/work-orchestrator into a PHP application through an interactive task-definition gate, then design or implement finite work queues, strategies, managed tasks, process-local resources, observability, cancellation, and failure recovery. Use when adopting or changing this library; not for durable message brokers, perpetual consumers, or generic process code unrelated to its public API.
---

# Work Orchestrator Integration

Turn an application goal into an explicit, implementation-ready contract before
choosing library components or writing code. Preserve the application's
business semantics; the library owns finite process orchestration, not domain
decisions or durable state.

## Start With The Interactive Clarity Gate

Do not begin implementation from a vague request such as “parallelize this” or
“add workers.” First ask, in the user's language:

> What finite set of work should the library process, and what observable
> result means that the run succeeded?

Read [references/task-intake.md](references/task-intake.md) and conduct its
interactive intake. Reuse facts the user already supplied and ask only about
unresolved material decisions, in batches of at most three short questions.
Do not silently choose ordering dependencies, process boundaries, payload
serialization, side-effect idempotency, or failure semantics.

Before code or configuration changes, present the resulting Integration
Contract and ask the user to confirm or correct it. If the user explicitly asks
to proceed with assumptions, record each assumption and its consequence in the
contract instead of pretending the ambiguity is resolved.

## Inspect The Actual Installation

After the contract is accepted:

1. Locate the installed `fedor/work-orchestrator` package or its source checkout.
2. Inspect its `composer.json`, version, relevant public interfaces, and the
   closest executable example. Do not rely on remembered signatures.
3. Read only the matching guide routes from
   [references/implementation-map.md](references/implementation-map.md).
4. Preserve repository-local instructions and the application's established
   framework, container, tests, and runtime wrappers.

If the package is not installed, confirm the intended version constraint and
installation scope before changing Composer state.

## Design From Outcomes To Components

Keep these decisions separate:

- `WorkQueue` owns root-local admission, ordering, and barriers.
- `Strategy` owns useful processing of one admitted `WorkEnvelope` in primary.
- `BootstrapsApplication` owns primary-local resources.
- Managed tasks split one admitted item into structured Concurrent Fibers or
  Parallel child-process work; they do not create a durable dynamic queue.
- Root hooks observe the run; `ApplicationEvent` values cross IPC from active
  tasks; `RunResult` is the final ledger.
- Application code owns transactions, checkpoints, deduplication, durable
  restart decisions, and the meaning of success.

Prefer ready queues and capabilities. Implement a custom `WorkQueue` only when
the accepted contract contains an admission rule that ordered/grouped/file
queues cannot express. Never extend `@internal` types.

## Implement A Complete Vertical Slice

For the first accepted scenario, include all of the following when applicable:

1. Composer-autoloadable payload and `Strategy` classes.
2. Stable, unique `WorkId` construction and bounded serializable payloads.
3. Root composition with explicit process and IPC limits.
4. Process-local resource creation rather than serialized live resources.
5. Result handling for success, partial failure, and cancellation.
6. The agreed retry/idempotency behavior without inventing durable guarantees.
7. A reproducible invocation with input, command, expected result, and a test
   that crosses the real process boundary when the integration does.

Keep teaching prose in documentation and tests; keep production code comments
for non-obvious invariants only.

## Verify The Accepted Contract

Run the project's narrow checks first, then its normal full gate. Verify the
observable result from the Integration Contract, not merely class construction.
For custom queues, test `Granted` / `Blocked` / `Exhausted`, exact lease
identity, readiness wake-up, duplicate completion, and cancellation. For
managed tasks or events, execute a real child/IPC path rather than a same-process
mock when the environment permits it.

Report any mismatch between the requested semantics and the current public API
as an implementation-friction finding. Do not bypass it with `Internal` classes.
