# Changelog

[Русский](CHANGELOG.md)

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
