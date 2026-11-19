# Development and change history

[Русский](CONTRIBUTING.md)

The canonical package history lives in the standalone
`wolfcharaa/work-orchestrator` repository. Consumer application changes belong
to their own repositories and stay separate from the library's public contract.

## Commit boundaries

- One commit contains one reason to change the public package.
- Consumer integration and lock-file updates are committed separately in the
  consumer repository.
- A performance checkpoint includes benchmark code and synchronized RU/EN
  results; large raw data is not committed.
- Messages follow the parent repository conventions, for example
  `FEAT(work-orchestrator): Добавлен process-local task pipeline`.
- Public API, documentation, and tests for one contract may form one atomic
  commit. Unrelated refactoring stays separate.

These boundaries preserve independent release and consumer histories.

## Releasing

Release only from a clean `main` after RU/EN documentation parity and all checks
pass. An annotated Git tag such as `v0.4.0` defines the version; Composer does
not keep a separate version field.

```bash
composer validate --strict
composer check
composer benchmark:worker-files -- --help
composer benchmark:record-pipeline -- --help
git tag -a v0.4.0 -m "Work Orchestrator 0.4.0"
```

Publish the verified `main` first, then tag and publish the same commit.
Packagist must point to the canonical GitHub repository. The release is not
consumer-ready until `composer show fedor/work-orchestrator --all` discovers the
new tag.

## Checks

From the package root:

```bash
composer check
composer benchmark:worker-files -- --help
composer benchmark:record-pipeline -- --help
```

The root application lock remains a separate compatibility check. A library
commit must not silently mutate its dependency graph.
