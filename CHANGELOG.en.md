# Changelog

[Русский](CHANGELOG.md)

## 1.0.0 — Unreleased

- Replaced the `amphp/parallel` backend with a root-owned direct process graph:
  every primary and managed worker is launched, finalized, terminated, and
  reaped by root over dedicated framed pipes.
- Kept the public finite-work, managed-task, cancellation, event, result, and
  recovery contracts while lowering the PHP baseline to 8.3.
- Production async foundations are limited to `amphp/amp` and
  `revolt/event-loop`; the package owns `proc_open`, protocol framing,
  stdout/stderr draining, and TERM-to-KILL escalation.
- Added clean-worker Composer autoload discovery and explicit overrides,
  bounded worker output tails, lazy managed pools, and compatibility with an
  already active Revolt loop.
- Added a clean PHP 8.3 consumer fixture that verifies both primary and managed
  execution and the exact five-package production dependency closure.

## 0.4.1 — 2026-11-19

- Reorganized public documentation around reader tasks with definitions, an
  observable quick start, queue and managed-task guides, lifecycle/recovery
  guidance, and a grouped public API reference.
- Added reproducible examples for generic work, managed child tasks,
  application events, and a custom `WorkQueue`; record import now includes a
  fixture, invocation, and expected result.
- The package now publishes an interactive `work-orchestrator` AI skill;
  `llm/skills` supports local development and syncs it from `resources/skills`.
- Corrected `ext-uv` provenance in benchmark documentation without changing
  production dependencies or the runtime API.

## 0.4.0 — 2026-11-19

- Released standalone `fedor/work-orchestrator` for finite concurrent and
  parallel queues with a bounded process topology.
- Added generic `WorkQueue`, `Strategy`, strict groups, and opportunistic
  admission without coupling core to a file format.
- Added reusable managed child workers, process-local bootstrap, cancellation,
  lifecycle hooks, and bounded structured diagnostics.
- `GroupedWorkQueue` grants every sibling in an open strict group before
  advancing to the next barrier.
- Migrating from `fedor/async-parser` is a breaking rename without compatibility
  aliases; the README documents the migration path.
