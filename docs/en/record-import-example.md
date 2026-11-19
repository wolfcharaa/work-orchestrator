# NDJSON parse-normalize-write example

[Русский](../ru/record-import-example.md) · [README](../../README.en.md)

This example does not add a parser to core. It demonstrates ownership: root
discovers files, primary opens its assigned file, normalizes records line by
line, and publishes output through a temporary `.part` file.

## Run the supplied fixture

From the checkout root:

```bash
mkdir -p var/record-import-output
composer example:record-import -- examples/fixtures var/record-import-output
```

Output contains absolute canonical paths, so its prefix depends on checkout
location. Expected line shape:

```text
<repo>/examples/fixtures/records.ndjson: 3 records in 1 write batches -> var/record-import-output/records-<12-hex>.normalized.ndjson
```

Inspect the output:

```bash
sed -n '1,3p' var/record-import-output/*.normalized.ndjson
```

Expected contents:

```jsonl
{"id":"1","name":"First"}
{"id":"2","name":"Second"}
{"id":"3","name":"Third"}
```

## Component responsibilities

| Component | Responsibility |
| --- | --- |
| [`record-import.php`](../../examples/record-import.php) | Validates CLI arguments, builds `FileWorkQueue`, sets primary limit, prints results |
| [`ImportRecords`](../../examples/RecordImport/ImportRecords.php) | `BootstrapsApplication`: creates parser/normalizer/sink once per primary and coordinates one file task |
| [`NdjsonRecordParser`](../../examples/RecordImport/NdjsonRecordParser.php) | Opens the stream in primary, reads complete lines, observes cancellation and record-size bound |
| [`RecordNormalizer`](../../examples/RecordImport/RecordNormalizer.php) | Validates application schema and normalizes `id`/`name` |
| [`NdjsonRecordSink`](../../examples/RecordImport/NdjsonRecordSink.php) | Writes bounded batches to `.part`, renames on commit, removes partial output on rollback |
| [`ImportResult`](../../examples/RecordImport/ImportResult.php) | Small serializable result: output path, record count, write-batch count |

The worker payload is `FileEntry(path, size)`, not NDJSON contents. Bulk data
therefore remains inside primary and does not consume the IPC limit.
`writeBatchRows` bounds pending in-memory rows; `maxRecordBytes` bounds one input
line.

## Guarantees and limitations

- Root discovery fixes the manifest before workers start; file changes after
  preflight remain the application's responsibility.
- `.part` is removed on the normal exception path. Whole OS/runtime death may
  leave it behind; `begin()` removes the same deterministic stale name on retry.
- Publishing one output uses rename, but the example does not provide a
  database transaction, durable checkpoint, or exactly-once semantics.
- An invalid record fails the current file item; no retry policy is enabled.
- Output name hashes the full source path, avoiding collisions between equal
  basenames.

For production, replace the normalizer and sink with application services while
preserving process-local ownership, bounded buffering, cancellation, and an
explicit transaction/idempotency policy. The behavior is covered by
[`RecordImportExampleTest`](../../tests/RecordImportExampleTest.php).
