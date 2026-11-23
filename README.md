# Work Orchestrator

[English](README.en.md)

`fedor/work-orchestrator` запускает конечный набор задач приложения в нескольких
процессах ОС и возвращает единый результат запуска. Библиотека управляет
выдачей задач, ограничениями параллелизма, отменой, событиями и завершением
worker-процессов. Приложение по-прежнему определяет смысл задачи, работу с БД и
файлами, транзакции, повторяемость операций и долговременное хранение состояния.

Библиотека подходит, когда набор работ можно сформировать до запуска и нужно
ограниченно распределить его между процессами. Она не является очередью
сообщений, планировщиком фоновых заданий или механизмом checkpoint/resume после
полной остановки приложения.

## Минимальная модель

- **root-процесс** — исходный процесс приложения: хранит очередь, решает, какую
  задачу можно выдать, собирает события и результаты;
- **primary worker** — дочерний процесс первого уровня: выполняет
  пользовательскую `Strategy` и переиспользует созданные в нём ресурсы;
- **child worker** — необязательный логический depth-two worker для
  управляемой подзадачи; как OS-процесс он создаётся напрямую root;
- **admission (допуск)** — решение очереди выдать задачу сейчас, подождать
  условие или сообщить, что задач больше нет;
- **payload (данные задачи)** — небольшое сериализуемое сообщение для worker,
  обычно идентификатор, путь, ключ или диапазон;
- **IPC (inter-process communication, межпроцессное взаимодействие)** — канал,
  по которому передаются задачи, события и результаты;
- **cancellation (отмена)** — сигнал прекратить выдачу новой работы и совместно
  завершить уже начатую.

Полные определения и схема первого запуска находятся в
[руководстве по началу работы](docs/ru/getting-started.md).

## Установка и первый результат

Требуются PHP 8.3+ и Composer. Создайте чистый consumer project, установите
пакет и скопируйте из него минимальные application-классы:

```bash
mkdir work-orchestrator-quick-start
cd work-orchestrator-quick-start
composer init --name=app/work-orchestrator-quick-start --license=proprietary --autoload=src/ --no-interaction
composer require fedor/work-orchestrator:^1.0
cp -R vendor/fedor/work-orchestrator/examples/consumer/src .
cp -R vendor/fedor/work-orchestrator/examples/consumer/bin .
composer dump-autoload
php bin/rebuild-customers.php
```

Ожидаемый вывод:

```text
customer:42 => 42
customer:77 => 77
run => succeeded
```

Сценарий создаёт две задачи с устойчивыми `WorkId`, допускает их через
`OrderedWorkQueue`, выполняет
`App\WorkOrchestratorQuickStart\Work\RebuildCustomerStrategy` в двух primary
workers и печатает результаты в порядке очереди. Полный fixture с собственным
Composer/autoload contract находится в
[`examples/consumer`](examples/consumer/composer.json). Payload и стратегия
живут в application namespace `App\WorkOrchestratorQuickStart`, поэтому Composer
может загрузить их в новых worker-процессах; объявлять такие классы только
внутри запускаемого скрипта нельзя.

Production dependency foundation намеренно узок: `amphp/amp` и
`revolt/event-loop` дают cancellation, Futures, Fibers, timers и readiness
дескрипторов. Библиотека сама владеет `proc_open`, fd 3/4 IPC,
stdout/stderr drain, TERM → KILL и reap. `amphp/parallel`, `amphp/process` и
`amphp/sync` не входят в production graph. `run()` одинаково работает
из plain CLI и внутри уже управляемого Amp Fiber, не запуская второй
event loop.

Для разработки самой библиотеки тот же результат остаётся доступен из checkout:

```bash
composer install
composer example:basic
```

## AI skill для интеграции

Пакет поставляет skill `work-orchestrator` для coding agents. Если проект
использует [`llm/skills`](https://github.com/roxblnfk/skills), установите plugin
как dev dependency и синхронизируйте skill из установленной библиотеки:

```bash
composer require --dev llm/skills
composer skills:update fedor/work-orchestrator
```

Затем вызовите `$work-orchestrator`. До выбора классов или написания кода skill
интерактивно уточнит конечный набор работ, наблюдаемый результат, identity и
payload, порядок/барьеры, process-local ресурсы, подзадачи, retry/idempotency,
cancellation и владельца durable recovery. После ответов он покажет единый
Integration Contract и попросит подтвердить его.

Исходник skill находится в
[`resources/skills/work-orchestrator`](resources/skills/work-orchestrator/SKILL.md).

## Куда идти дальше

| Задача | Руководство |
| --- | --- |
| Понять сериализацию, `WorkId`, результат и ограничения первого запуска | [Начало работы](docs/ru/getting-started.md) |
| Выбрать обычную, групповую, файловую или собственную очередь | [Очереди, барьеры и расширение допуска](docs/ru/work-queues.md) |
| Создать process-local ресурсы или разнести подзадачи по Fiber/child workers | [Управляемые задачи](docs/ru/managed-tasks.md) |
| Подключить hooks, события, диагностику и восстановление | [Наблюдаемость и восстановление](docs/ru/observability-and-recovery.md) |
| Найти роль конкретного публичного класса или интерфейса | [Справочник публичного API](docs/ru/public-api.md) |
| Разобрать протокол root → primary → child | [Runtime-протокол](docs/ru/worker-pipeline.md) |
| Запустить parse-normalize-write для NDJSON | [Пример импорта записей](docs/ru/record-import-example.md) |

## Гарантии и границы

`Orchestrator` одноразовый: после начала `run()` его нельзя перенастроить или
запустить повторно. Возвращаемый `RunResult` сохраняет успешные и неуспешные
исходы уже допущенных задач, ограниченную диагностику и ошибки observers.

Ядро не парсит XML/CSV/JSON, не открывает транзакции, не хранит durable
inbox/outbox и не выбирает бизнес-порядок. Большие данные не следует переносить
через IPC: worker должен получить рецепт доступа к ним и открыть ресурс локально.
После смерти всего runtime решение о новом запуске и защита от повторных
эффектов принадлежат приложению или оператору.

## Миграция

В `1.0` backend `amphp/parallel` заменён root-owned direct process graph.
Знакомые Amp-контракты `Cancellation`, `Future`, hooks и
`ExecutionMode` сохранены; новые process settings — `forceKillAfter()`,
`workerOutputTailBytes()` и `workerAutoloader()`. Проверьте crash/retry и
cleanup на alpha/RC до stable cutover; rollback leg — `0.4.x`.

Более ранняя линия `0.4` была несовместимым переименованием
`fedor/async-parser` без compatibility aliases. Package
и namespace стали `fedor/work-orchestrator` и `Fedor\WorkOrchestrator`;
`AsyncParser::fromFiles()` заменён явной композицией
`Orchestrator::fromQueue(FileWorkQueue::discover(...))`; вместо file-specific
handler registry используется `Strategy` с опциональными capability-интерфейсами.
Обновляйте constraint, imports и composition одним согласованным изменением.

Изменения релизов: [CHANGELOG.md](CHANGELOG.md).
