# Worker orchestration and structural-pipeline benchmarks

[Русский](README.md)

Version 0.4 uses a single Strategy API and has no universal filesystem byte
reader. Its benchmarks exercise
two real public-API boundaries:

- `worker-files.php` covers process creation, Strategy initialization, path
  admission, a selected benchmark-owned file read, compact results,
  finalization, and shutdown;
- `record-pipeline.php` covers Strategy bootstrap and a complete
  process-local NDJSON parse → normalize → batched sink pipeline.

These are different measurements. The first does not prove XML parsing speed;
the second does not model PostgreSQL, COPY, WAL, or durable fsync.

## Pinned environment

Accepted runs use `geo-registry-amp-app` with PHP 8.4.27. Dataset and package
mounts must remain visible to child workers under the same absolute paths.
Composer scripts set `memory_limit=-1`; the container/cgroup remains the hard
limit.

JSON reports include PHP version/binary/SAPI, assertions, OPcache/JIT, Xdebug,
event-loop driver, worker count, manifest identity, duration, throughput, and
parent PHP peak. Parent peak excludes child RSS; sample container memory
separately with `docker stats`.

## Worker benchmark

```bash
composer benchmark:worker-files -- \
  --driver=auto \
  --workers=28 \
  --pattern='/\.xml$/i' \
  --expected-files=28 \
  --expected-bytes=24975511491 \
  <GAR_RELEASE_DIR>/50 <GAR_RELEASE_DIR>/*.XML
```

Options:

- `--driver=auto|native|blocking|uv|eio` selects the benchmark-owned reader;
  `WORK_ORCHESTRATOR_BENCHMARK_FILE_DRIVER` takes precedence over the CLI value;
- `--workers=N` controls process/file concurrency;
- repeat `--pattern` for directory discovery filters;
- `--expected-files` and `--expected-bytes` reject fixture substitution before
  the timer.

`auto` checks `uv`, then `eio`, and finally uses native `fopen/fread`. The
process-backed `amphp/file` fallback is intentionally excluded from auto: the
library already executes each file task in an OS worker, so another hidden
process pool would change the measured topology. `blocking` remains available
to diagnose the Amp abstraction cost.

The internal `1 MiB` reads belong only to the benchmark Strategy and are reported
as `task_read_bytes`; this is not a public parser setting and does not restore
arbitrary byte batches to core. Success requires an exact byte count for every
manifest path.

## Structural record pipeline

```bash
composer benchmark:generate-record-fixture -- /tmp/work-orchestrator-records 8 250000
mkdir -p /tmp/work-orchestrator-normalized

composer benchmark:record-pipeline -- \
  --workers=8 \
  --write-batch-rows=5000 \
  --expected-records=2000000 \
  --expected-files=8 \
  --expected-input-bytes=84888896 \
  /tmp/work-orchestrator-records /tmp/work-orchestrator-normalized
```

Fixture generation and discovery are outside the timer. The timer includes
worker bootstrap, native line reads, JSON decode, normalization, sink batching,
file writes, finalization, and shutdown. The report verifies records, input
bytes, output bytes, and a stable output-manifest digest. `writeBatchRows`
configures the sink, not the reader.

## Performance gate

- Match the image, PHP runtime identity, fixture manifest, and cache protocol
  before comparison.
- A difference below `2–3%` with comparable spread is performance-neutral.
- Repeat a positive first point as A/B; one discriminating negative point may
  close a candidate.
- Accept throughput only with correctness counters and digest.
- Do not infer child RSS, CPU, I/O, or page-cache state from parent PHP peak.

For a cold-cache experiment, request `fadvise --advice dontneed` for every
manifest file and record verifiable resident state with `fincore`. Calling
`fadvise` alone does not prove every page was immediately evicted.

## Historical 0.1 line

Old results are retained only as context and must not be pooled with 0.2:

- GAR 2026-09-01 v.288, region 50 plus shared: 28 XML files,
  `24,975,511,491` bytes;
- old parent UV reader: `8.736942 s` mean;
- old `DirectFileTask` W28: `8.093600 s` mean;
- old bootstrapped Handler W28: `7.952091 s` mean;
- deterministic 2M-record NDJSON: W1 `35.413665 s`, W4 `10.097721 s`, and
  W8 `6.629035 s` mean.

Those points used `FileInput`/`amphp/file` and the former asynchronous sink.
They demonstrate the value of process-local file parallelism but are not an
acceptance baseline for 0.2. The path-first refactor needs a separate 0.2 A/B on
the same image and manifest; add results only after exact gates pass.

## 0.2 path-first control: 2026-11-16

Runtime: the same PHP `8.4.27`, assertions/OPcache/JIT disabled, ext-uv `0.3.1`.
The worker benchmark used the same `28 / 24,975,511,491` GAR manifest, requested
eviction before each accepted point, and observed `RES=0` for the largest file
through `fincore`.

| Mode | Runs, seconds | Mean, seconds | Versus historical 0.1 | Decision |
|---|---:|---:|---:|---|
| Direct W28 | `8.283557; 7.869466` | `8.076511` | `-0.21%` | performance-neutral |
| Handler W28 | `7.281357; 8.193891` | `7.737624` | `-2.70%` | `11.79%` spread; gain unproven |

The warm `1.729–1.746 s` diagnostic is not NVMe throughput: it is aggregate
reading by 28 workers from the Linux page cache. Removing the core reader proves
neither an independent raw-read gain nor a regression.

On the same deterministic NDJSON fixture, the new structural `fgets()` pipeline
produced:

| Workers | 0.2 runs, seconds | Historical 0.1 | Change |
|---:|---:|---:|---:|
| 1 | `1.164175` | `35.413665` | `30.42×`; elapsed `-96.71%` |
| 8 | `0.253837; 0.256175`, mean `0.255006` | `6.629035` mean | `26.00×`; elapsed `-96.15%` |

Every point returned exactly `2,000,000` records, `400` writes,
`84,888,896` input/output bytes, and digest
`4df10926a7d46c5a04ee57154e49c0975fb490ef18ef44cba8306e6ca2c7523b`.
W8 scales `4.57×` over the new W1.

Disk reads are not the cause. The old NDJSON parser searched for newlines in a
`1 MiB` `$carry` and created a new remainder with `substr` for every line,
causing repeated copying. The path-first parser receives complete lines from
`fgets()`; Amp file/Future boundaries and the async wrapper around a regular
worker-local output file are also gone. This is evidence for structural
ownership, not a universal speedup claim for every format.

## q99b: native versus ext-uv after the path-first refactor

`amphp/file 4.0.0` is restored only in `require-dev`, while `ext-uv` was
installed separately in the benchmark environment and is not declared as a
package requirement or suggestion. The benchmark factory is created inside a worker: it
first applies `WORK_ORCHESTRATOR_BENCHMARK_FILE_DRIVER`, then `auto` selects
`uv → eio → native`. No new reader or Strategy is exposed by the library's
public API.

The cold crossover used the same `28 / 24,975,511,491` manifest, W28,
PHP `8.4.27`, and ext-uv `0.3.1`; `fincore` reported `resident=0` before every
point. Every variant returned the exact byte count and `23,840` read calls.

| Driver | Runs, seconds | Mean, seconds | Versus native mean | Decision |
|---|---:|---:|---:|---|
| native `fopen/fread` | `7.247626; 8.194367` | `7.720996` | control | — |
| `UvFilesystemDriver` | `7.176245; 8.023598` | `7.599922` | `-1.568%` | performance-neutral |
| `BlockingFilesystemDriver` | `7.892898` | one diagnostic point | `+2.226%` | not an acceptance result |

UV won both orderings by `0.985% / 2.084%`, but its mean gain stays below the
`2–3%` gate while the series spreads are `12.26% / 11.15%`. Thus `auto`
proves that the UV backend works, but the result does not justify moving the
factory into production core and does not prove faster XML parsing. A future
Strategy must be measured with a structural parser and a waiting sink, where
non-blocking I/O can overlap useful work.

## Bottleneck interpretation

- W1 approximates one process's parser/sink cost.
- W2…WN shows scaling across files until CPU, disk, or downstream reaches its
  knee.
- Raising workers does not accelerate one large file.
- More workers add PHP runtimes/application graphs and may hurt cache locality.
- Fast raw reads with a slow record pipeline identify decode, transform, or
  sink bottlenecks; they do not justify restoring byte batches to core.

A future `Strategy` benchmark must compare structural parsers on one semantic
fixture and the same result digest. Byte scans alone are insufficient.
