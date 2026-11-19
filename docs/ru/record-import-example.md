# Пример NDJSON parse-normalize-write

[English](../en/record-import-example.md) · [README](../../README.md)

Этот пример показывает не «парсер внутри библиотеки», а способ владения
ресурсами: root находит файлы, primary открывает назначенный файл, построчно
нормализует записи и публикует результат через временный `.part` файл.

## Запуск готовой fixture

Из корня checkout:

```bash
mkdir -p var/record-import-output
composer example:record-import -- examples/fixtures var/record-import-output
```

Вывод содержит absolute canonical paths, поэтому prefix зависит от расположения
checkout. Форма ожидаемой строки:

```text
<repo>/examples/fixtures/records.ndjson: 3 records in 1 write batches -> var/record-import-output/records-<12-hex>.normalized.ndjson
```

Проверьте созданный файл:

```bash
sed -n '1,3p' var/record-import-output/*.normalized.ndjson
```

Ожидаемое содержимое:

```jsonl
{"id":"1","name":"First"}
{"id":"2","name":"Second"}
{"id":"3","name":"Third"}
```

## Что делает каждый компонент

| Компонент | Ответственность |
| --- | --- |
| [`record-import.php`](../../examples/record-import.php) | Проверяет CLI arguments, строит `FileWorkQueue`, задаёт primary limit, печатает результаты |
| [`ImportRecords`](../../examples/RecordImport/ImportRecords.php) | `BootstrapsApplication`: создаёт parser/normalizer/sink один раз на primary и координирует одну file task |
| [`NdjsonRecordParser`](../../examples/RecordImport/NdjsonRecordParser.php) | Открывает файловый stream внутри primary, читает по строке, соблюдает отмену и ограничение размера записи |
| [`RecordNormalizer`](../../examples/RecordImport/RecordNormalizer.php) | Проверяет application schema и нормализует `id`/`name` |
| [`NdjsonRecordSink`](../../examples/RecordImport/NdjsonRecordSink.php) | Пишет ограниченные batches в `.part`, атомарно переименовывает при commit, удаляет частичный файл при rollback |
| [`ImportResult`](../../examples/RecordImport/ImportResult.php) | Маленький сериализуемый итог: output path, число records и write batches |

Payload worker — `FileEntry(path, size)`, а не содержимое NDJSON. Это сохраняет
большие данные внутри primary и не расходует лимит IPC. `writeBatchRows` ограничивает
in-memory pending rows, `maxRecordBytes` — размер одной входной строки.

## Гарантии и ограничения примера

- Root discovery фиксирует manifest до старта workers; изменение файла после
  preflight остаётся ответственностью приложения.
- `.part` удаляется при обычном exception path. Полная смерть OS/runtime может
  оставить stale `.part`; `begin()` удалит файл с тем же deterministic name при
  следующей попытке.
- Публикация одного output использует rename, но пример не предоставляет
  database transaction, durable checkpoint или exactly-once semantics.
- Неверный record завершает текущий file work item; политика retry не включена.
- Output filename включает hash полного source path, поэтому две fixture с
  одинаковым basename не конфликтуют.

Для production замените normalizer и sink на application services, сохранив
владение ресурсами внутри процесса, ограниченный буфер, отмену и явную transaction /
idempotency policy. Поведение примера проверяет
[`RecordImportExampleTest`](../../tests/RecordImportExampleTest.php).
