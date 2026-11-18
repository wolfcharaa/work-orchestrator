# Benchmark worker orchestration и structural pipeline

[English](README.en.md)

Версия 0.4 использует единый Strategy API и не содержит универсального
filesystem byte reader. Benchmark
проверяет две реальные границы публичного API:

- `worker-files.php` — создание процессов, Strategy initialization, назначение
  paths, выбранное benchmark-owned file read, compact result, finalization и
  shutdown;
- `record-pipeline.php` — Strategy bootstrap и полный process-local NDJSON
  parse → normalize → batched sink pipeline.

Это разные измерения. Первое не доказывает скорость XML parsing; второе не
моделирует PostgreSQL, COPY, WAL или durable fsync.

## Закреплённая среда

Зачётные прогоны выполняются в `geo-registry-amp-app` с PHP 8.4.27. Dataset и
package mount должны быть видны дочерним worker по тем же absolute paths.
Composer scripts запускают PHP с `memory_limit=-1`; реальный hard limit задаёт
container/cgroup.

JSON report фиксирует PHP version/binary/SAPI, assertions, OPcache/JIT, Xdebug,
event-loop driver, worker count, manifest identity, duration, throughput и
parent PHP peak. Parent peak не включает child RSS; container memory измеряется
отдельно через `docker stats`.

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

- `--driver=auto|native|blocking|uv|eio` выбирает benchmark-owned reader;
  `WORK_ORCHESTRATOR_BENCHMARK_FILE_DRIVER` имеет приоритет над CLI;
- `--workers=N` задаёт process/file concurrency;
- `--pattern` повторяется для directory discovery;
- `--expected-files` и `--expected-bytes` отклоняют подменённый fixture до
  таймера.

`auto` проверяет `uv`, затем `eio`, затем использует native `fopen/fread`.
Process-backed fallback `amphp/file` намеренно не входит в auto: библиотека уже
исполняет file task в отдельном OS worker, поэтому ещё один скрытый process pool
изменил бы измеряемую topology. `blocking` оставлен как диагностическая точка
цены Amp abstraction.

Внутреннее чтение по `1 MiB` принадлежит только benchmark Strategy и отражается как
`task_read_bytes`; это не публичный parser setting и не возвращает arbitrary
byte batches в core. Успех требует точного числа bytes для каждого manifest
path.

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

Fixture generation и discovery исключены из timer. В timer входят worker
bootstrap, native line reads, JSON decode, normalization, sink batching, file
writes, finalization и shutdown. Report проверяет records, input bytes, output
bytes и stable manifest digest. `writeBatchRows` управляет sink, а не reader.

## Performance gate

- До сравнения совпадают image, PHP runtime identity, fixture manifest и cache
  protocol.
- Изменение меньше `2–3%` при сопоставимом spread считается
  performance-neutral.
- Положительная первая точка повторяется A/B; отрицательная может быть закрыта
  одной discriminating точкой.
- Throughput принимается только вместе с correctness counters/digest.
- Child RSS, CPU, I/O и page-cache state не выводятся из parent PHP peak.

Для cold-cache эксперимента `fadvise --advice dontneed` запрашивается для всех
manifest files, а `fincore` фиксирует проверяемую resident-state. Сам вызов
`fadvise` не доказывает, что все pages немедленно evicted.

## Историческая линия 0.1

Старые результаты сохранены только как ориентир и не смешиваются с 0.2:

- GAR 2026-09-01 v.288, region 50 + shared: 28 XML,
  `24 975 511 491` bytes;
- old parent UV reader: mean `8.736942 s`;
- old `DirectFileTask` W28: mean `8.093600 s`;
- old bootstrapped Handler W28: mean `7.952091 s`;
- deterministic NDJSON 2M records: W1 `35.413665 s`, W4 `10.097721 s`,
  W8 mean `6.629035 s`.

Эти числа включали `FileInput`/`amphp/file` и прежний async sink. Они показывают
ценность process-local file parallelism, но не являются baseline для принятия
0.2. После path-first refactor нужен отдельный 0.2 A/B на том же image и
manifest; результат добавляется ниже только после exact gates.

## Контроль 0.2 path-first: 2026-11-16

Runtime: тот же PHP `8.4.27`, assertions/OPcache/JIT выключены, ext-uv `0.3.1`.
Worker benchmark использовал тот же GAR manifest `28 / 24 975 511 491` и перед
каждой зачётной точкой запрашивал eviction; у крупнейшего файла `fincore`
показывал `RES=0`.

| Mode | Прогоны, s | Среднее, s | К historical 0.1 | Решение |
|---|---:|---:|---:|---|
| Direct W28 | `8.283557; 7.869466` | `8.076511` | `-0.21%` | performance-neutral |
| Handler W28 | `7.281357; 8.193891` | `7.737624` | `-2.70%` | spread `11.79%`, gain не доказан |

Warm diagnostic `1.729–1.746 s` не является NVMe throughput: это агрегированное
чтение 28 worker из Linux page cache. Удаление core reader не дало отдельного
доказанного raw-read выигрыша и не создало регрессии.

На том же deterministic NDJSON fixture новый structural `fgets()` pipeline дал:

| Workers | 0.2 прогоны, s | Historical 0.1 | Изменение |
|---:|---:|---:|---:|
| 1 | `1.164175` | `35.413665` | `30.42×`, elapsed `-96.71%` |
| 8 | `0.253837; 0.256175`, mean `0.255006` | mean `6.629035` | `26.00×`, elapsed `-96.15%` |

Каждая точка вернула exact `2 000 000` records, `400` writes,
`84 888 896` input/output bytes и digest
`4df10926a7d46c5a04ee57154e49c0975fb490ef18ef44cba8306e6ca2c7523b`.
W8 масштабируется от нового W1 в `4.57×`.

Причина не в disk read: старый NDJSON parser искал newline внутри `1 MiB`
`$carry` и на каждой строке создавал новый остаток через `substr`, что давало
многократное копирование. Path-first parser получает целые строки через
`fgets()`; также удалены Amp file/Future boundaries и async wrapper вокруг
обычного worker-local output file. Это performance evidence в пользу
structural ownership, но не универсальное обещание ускорить любой format.

## q99b: native и ext-uv после path-first refactor

`amphp/file 4.0.0` возвращён только в `require-dev`, а `ext-uv` — в Composer
`suggest`. Benchmark factory создаётся внутри worker: сначала применяется
`WORK_ORCHESTRATOR_BENCHMARK_FILE_DRIVER`, затем `auto` выбирает
`uv → eio → native`. В публичном API библиотеки нового reader/Strategy нет.

Cold crossover использовал тот же manifest `28 / 24 975 511 491`, W28,
PHP `8.4.27`, ext-uv `0.3.1`; перед каждой точкой `fincore` показывал
`resident=0`. Все варианты вернули exact bytes и `23 840` read calls.

| Driver | Прогоны, s | Среднее, s | К native mean | Решение |
|---|---:|---:|---:|---|
| native `fopen/fread` | `7.247626; 8.194367` | `7.720996` | control | — |
| `UvFilesystemDriver` | `7.176245; 8.023598` | `7.599922` | `-1.568%` | performance-neutral |
| `BlockingFilesystemDriver` | `7.892898` | одна diagnostic point | `+2.226%` | не acceptance result |

UV выиграл в обоих порядках на `0.985% / 2.084%`, но средний gain ниже gate
`2–3%`, а spread серий составил `12.26% / 11.15%`. Поэтому `auto` подтверждает
работоспособность UV backend, но результат не оправдывает перенос фабрики в
production core и не доказывает ускорение XML parser. Следующая Strategy должна
измеряться вместе со structural parser и ожидающим sink, где неблокирующее I/O
может перекрыть полезную работу.

## Bottleneck interpretation

- W1 близок к parser/sink cost одного process.
- W2…WN показывает масштабирование разных файлов, пока CPU/disk/downstream не
  достиг knee.
- Один большой файл не ускоряется увеличением workers.
- Рост worker count увеличивает PHP runtimes/application graphs и может
  ухудшить cache locality.
- Быстрый raw read при медленном record pipeline означает bottleneck decode,
  transform или sink, а не повод возвращать byte batches в core.

Будущий `Strategy` benchmark должен измерять каждый structural parser на одном
semantic fixture и одинаковом result digest; сравнивать только byte scans
недостаточно.
