# Work Orchestrator

[English](README.en.md)

`fedor/work-orchestrator` распределяет конечный набор application-owned задач
между OS-процессами и управляет их жизненным циклом. Библиотека отвечает за
admission, лимиты root/primary/child, cancellation, события, диагностику и
cleanup. Смысл задачи остаётся в пользовательской `Strategy`.

Файлы — готовый адаптер, а не ограничение ядра. Payload может быть командой,
DTO, ссылкой на диапазон данных или `FileEntry`.

## Установка

Пакет пока инкубируется внутри родительского repository и не объявляет
отдельный публичный Composer release channel. В текущем приложении path
repository уже настроен. Для другого checkout сначала добавьте локальный path и
явное соответствие версии:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/absolute/path/to/work-orchestrator",
            "options": {
                "symlink": true,
                "versions": {
                    "fedor/work-orchestrator": "0.4.0"
                }
            }
        }
    ]
}
```

После настройки repository:

```bash
composer require fedor/work-orchestrator:^0.4
```

Требуется PHP 8.4+. Объекты, пересекающие границу процесса, должны
сериализоваться Composer worker-окружением.

## Минимальный запуск

```php
<?php

use Amp\Cancellation;
use Fedor\WorkOrchestrator\Contract\Strategy;
use Fedor\WorkOrchestrator\Orchestrator;
use Fedor\WorkOrchestrator\Scheduling\OrderedWorkQueue;
use Fedor\WorkOrchestrator\WorkEnvelope;
use Fedor\WorkOrchestrator\WorkId;

final readonly class RebuildCustomer
{
    public function __construct(public int $customerId) {}
}

/** @implements Strategy<RebuildCustomer, int> */
final class RebuildCustomerStrategy implements Strategy
{
    /** @param WorkEnvelope<RebuildCustomer> $work */
    public function run(WorkEnvelope $work, Cancellation $cancellation): int
    {
        $cancellation->throwIfRequested();

        return $work->payload->customerId;
    }
}

$queue = new OrderedWorkQueue([
    new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
    new WorkEnvelope(new WorkId('customer:77'), new RebuildCustomer(77)),
]);

$result = Orchestrator::fromQueue($queue)
    ->primaryWorkers(2)
    ->run(new RebuildCustomerStrategy());

$customerId = $result->value(new WorkId('customer:42'));
```

`WorkId` стабильно связывает admission, события, результат и ошибку. Payload не
должен содержать большие данные: через IPC лучше передавать идентификатор,
путь, ключ или безопасный диапазон.

Экземпляр `Orchestrator` одноразовый. После начала `run()` его нельзя
перенастроить или запустить повторно.

## Файлы

`FileWorkQueue` один раз на root-стороне находит файлы, получает canonical path,
снимает размер и строит обычный `WorkQueue<FileEntry>`:

```php
use Fedor\WorkOrchestrator\File\FileOrder;
use Fedor\WorkOrchestrator\File\FileWorkQueue;

$queue = FileWorkQueue::discover(
    ['/data/incoming', '/data/manual.ndjson'],
    patterns: ['*.ndjson', '/^part-\d+\.json$/i'],
    order: FileOrder::largestFirst(),
);
```

Patterns применяются к содержимому каталогов; явно переданный файл всегда
включается. Доступны `pathAscending()`, `largestFirst()`, `smallestFirst()` и
`custom(Closure)`. Canonical path становится `WorkId`; дубли отклоняются до
старта worker-процессов. Библиотека не открывает и не проверяет тот же файл
повторно внутри worker.

Полный пример parse-normalize-write находится в
[`examples/record-import.php`](examples/record-import.php).

## Барьеры и полезная загрузка свободных worker

```php
use Fedor\WorkOrchestrator\AdmissionPriority;
use Fedor\WorkOrchestrator\File\FilePriority;

$queue = FileWorkQueue::discoverGrouped(
    ['/data/gar'],
    priority: FilePriority::fromBasenamePatterns(
        [
            0 => ['AS_ADDR_OBJ_*.XML'],
            10 => ['AS_HOUSES_*.XML'],
        ],
        unmatched: AdmissionPriority::opportunistic(),
    ),
    withinGroup: FileOrder::largestFirst(),
    patterns: ['*.XML'],
);
```

Все strict-задачи открытой группы должны завершиться до следующей группы.
Opportunistic-задачи могут занимать освободившиеся worker, пока strict-группа
ещё выполняется, но сами не открывают следующий барьер. Поэтому маленькие
независимые справочники уменьшают простой, не ломая зависимый порядок.

Для произвольных payload используйте `OrderedWorkQueue` или
`GroupedWorkQueue` напрямую либо реализуйте конечный root-local `WorkQueue`.

## Process-local bootstrap

Если стратегии нужны соединение с БД, parser или DI-контейнер, реализуйте
`BootstrapsApplication`. `boot()` вызывается один раз после старта каждого
primary worker, `close()` — при его завершении. Эти ресурсы не сериализуются из
root-процесса и могут повторно использоваться всеми задачами этого worker.

Для container-aware сценария доступны `ProvidesContainer` и
`ProvidesManagedTaskBootstrap`. Логгер также можно получить из собранного в
worker контейнера; отдельный logging contract ядру не нужен.

## Управляемые дочерние задачи

Стратегия с `UsesManagedTasks` получает новый `ManagedTaskGroup` для каждого
root work item:

```php
$first = $tasks->submit(new ParseRange($path, 0, 1000));
$second = $tasks->submit(new ParseRange($path, 1000, 2000));
$tasks->join();

$rows = [...$first->value(), ...$second->value()];
```

`ManagedTask` — маленькое сериализуемое сообщение, его `handler()` возвращает
класс `ManagedTaskHandler`. Группа структурная: дочерняя задача не переживает
родительскую. Глубина ограничена root → primary → child; child не создаёт ещё
один пул. Настройки:

- `primaryWorkers(n)` — одновременно активные primary OS-процессы;
- `childWorkersPerPrimary(n)` — переиспользуемые child-процессы на primary;
- `concurrentTasksPerPrimary(n)` — Fiber-конкурентность внутри primary;
- `maxIpcPayloadBytes(n)` — предел сериализованного control-plane payload;
- `cancellationGrace(seconds)` — время cooperative cleanup.

## Lifecycle и события

Root-local hooks задаются fluent-методами `beforeRun`, `onWorkerLifecycle`,
`beforeTask`, `onTaskLifecycle`, `onApplicationEvent`, `afterTask`, `afterRun`
и `onObserverFailure`. Они наблюдают выполнение, но не сериализуются в worker.

Application-код может отправить небольшое сериализуемое событие из стратегии,
handler или вложенного сервиса:

```php
ProcessEvents::emit(new RowsParsed($count));
```

Envelope сохраняет `runId`, `WorkId`, task/parent task, роль процесса, глубину,
PID и последовательность. Bulk data через event-канал передавать нельзя.

## Результаты, ошибки и recovery

`RunResult` — полный ledger, доступный и после частичной ошибки:

- `results()` — `WorkResult` для каждой принятой задачи;
- `values()` — успешные значения в admission order;
- `result(WorkId)` — non-throwing точечный доступ к outcome;
- `value(WorkId)` — значение либо typed `WorkFailed` с тем же diagnostic;
- `diagnostics()` — bounded structured failure evidence;
- `observerFailures()` — ошибки root observers;
- `throwIfFailed()` — опциональный переход к exception flow.

Диагностика содержит тип и категорию ошибки, origin
(role/executor/PID/WorkId/task/attempt), не более 5 stack frames и 3 previous
causes, а также явные признаки усечения. Аргументы функций, payload, locals,
environment и объекты контейнера в неё не попадают.

`HandlesTaskFailure` позволяет стратегии ограниченно повторить или завершить
текущий work item. `WorkerCrashPolicy` обрабатывает исчезновение primary
процесса отдельно. Библиотека не является durable dispatcher: после смерти
всего runtime решение о повторном запуске остаётся приложению или оператору.

## Миграция с `fedor/async-parser`

Линия `0.4` является breaking rename без compatibility aliases:

- package и namespace заменены на `fedor/work-orchestrator` и
  `Fedor\WorkOrchestrator`;
- `AsyncParser::fromFiles()` заменён явной композицией
  `Orchestrator::fromQueue(FileWorkQueue::discover(...))`;
- `FileTask`, handler registry и worker bootstrap заменены одной application
  `Strategy` и опциональными capability-интерфейсами;
- file path больше не является identity ядра: generic queue передаёт
  `WorkEnvelope(WorkId, payload)`, а результат читается через `WorkResult`.

Старые классы и facade намеренно не сохраняются. До обновления application
composition нужно заменить package constraint, namespace imports и построение
очереди одним согласованным срезом.

## Граница ответственности

Ядро не парсит XML/CSV/JSON, не делает checkpoint/resume, не хранит durable
inbox/outbox и не определяет бизнес-порядок. Оно гарантирует управляемое
распределение конечной очереди, ограниченную глубину процессов, cancellation,
cleanup, наблюдаемость и честный результат. Формат данных, транзакции,
идемпотентность и семантика retry принадлежат стратегии и приложению.

Подробнее о протоколе процессов: [docs/ru/worker-pipeline.md](docs/ru/worker-pipeline.md).
