# Development and change history

[Русский](CONTRIBUTING.md)

While the package is incubated inside `geo-registry`, the parent Git repository
is its canonical history. Do not create a nested `.git` directory in the
library. Without a registered submodule, the parent sees an embedded repository
and other checkouts lose the package contents.

## Commit boundaries

- Library changes use a path-scoped `app/library/work-orchestrator/**` commit with
  one reason for change.
- Application integration (`app/composer.json`, `app/composer.lock`, and
  application code) is a separate commit.
- A performance checkpoint includes benchmark code and synchronized RU/EN
  results; large raw data is not committed.
- Messages follow the parent repository conventions, for example
  `FEAT(work-orchestrator): Добавлен process-local task pipeline`.
- Public API, documentation, and tests for one contract may form one atomic
  commit. Unrelated refactoring stays separate.

These boundaries keep the current history readable and allow the package to be
extracted later without copying a snapshot by hand.

## Extracting standalone history

Once a dedicated remote and release policy exist, split the path history from
the parent repository:

```bash
git subtree split \
  --prefix=app/library/work-orchestrator \
  --branch=work-orchestrator-history
```

This command is not run yet and does not change the current layout. Review the
resulting branch, then push it to the new repository. Choose application
integration separately afterward: a Composer package, a registered Git
submodule, or an explicit subtree workflow. An unregistered nested repository
is never used.

Before extraction, reproducible checkpoints may use annotated tags prefixed
with `work-orchestrator/`; create a tag only after successful checks.

## Checks

From the package root:

```bash
composer check
composer benchmark:worker-files -- --help
composer benchmark:record-pipeline -- --help
```

The root application lock remains a separate compatibility check. A library
commit must not silently mutate its dependency graph.
