# Runtime-протокол Work Orchestrator

[English](../en/worker-pipeline.md)

Термины root/primary/child, admission (допуск), payload (данные задачи), IPC и
cancellation (отмена) определены в [руководстве по началу работы](getting-started.md).
На этой странице точные названия PHP API сохранены без перевода.

## Модель владения

```text
root process
  Orchestrator
    ├─ владеет конечным WorkQueue, допуском и барьерами
    ├─ создаёт primary pool заданного размера
    ├─ один раз устанавливает Strategy в каждый primary
    ├─ выдаёт WorkEnvelope и непрерывно принимает события
    ├─ агрегирует результаты в порядке допуска
    └─ отменяет, освобождает ресурсы и останавливает pool

primary worker (depth 1)
  WorkerRuntime
    ├─ при необходимости Strategy::boot()
    ├─ переиспользует process-local Strategy и ресурсы
    ├─ для каждого WorkEnvelope создаёт structured ManagedTaskGroup
    ├─ выполняет Strategy::run()
    ├─ дожидается всех managed tasks
    └─ закрывает child pool, затем Strategy::close()

child worker (depth 2)
  ManagedTaskHandler
    └─ выполняет небольшое сериализуемое ManagedTask-сообщение
```

Root координирует, но не знает смысла данных задачи. Primary выполняет полезную работу
стратегии. Child — опциональная изоляция подзадач стратегии. Управляемая глубина
намеренно заканчивается на child: вложенный child pool не создаётся.

## Почему допуск отделён от Strategy

`WorkQueue<TPayload>` остаётся в root и отвечает только за то, когда можно
выдать следующий `WorkEnvelope`. Strategy получает уже допущенную задачу и не
сортирует глобальный набор. Это разделяет два разных решения:

- очередь владеет порядком, строгими барьерами и независимыми fillers;
- стратегия владеет обработкой данных задачи, локальным восстановлением и дочерними задачами.

`acquire()` возвращает `Granted`, `Blocked` или `Exhausted`. `Granted` содержит
уникальный `TaskLease` (разрешение); только `complete()` этого lease снимает
состояние «выполняется». При `Blocked` worker асинхронно ждёт readiness-сигнал и
не опрашивает очередь в бесконечном цикле.

`OrderedWorkQueue` выдаёт элементы в заданном порядке без барьеров.
`GroupedWorkQueue` не открывает следующую строгую группу, пока завершены не все
задачи текущей. Независимый opportunistic-запас может занять свободный worker в ожидании
барьера, но не влияет на его открытие.

## Идентичность и данные задачи

```php
new WorkEnvelope(
    new WorkId('region:50:AS_ADDR_OBJ'),
    new ImportTable(/* serializable recipe */),
);
```

`WorkEnvelope` намеренно содержит только стабильный `WorkId` и application
payload. WorkId проходит через события жизненного цикла, результаты и диагностику.
Payload — управляющий рецепт, а не канал больших данных. Большие XML/record batches
следует держать внутри процесса или адресовать путём, ключом либо безопасным
диапазоном.

Стратегия сериализуется один раз на primary worker, а каждый WorkEnvelope — один
раз на назначенное выполнение. Это не shared memory: у каждого primary своя
копия Strategy и свои mutable process-local ресурсы.

## Дополнительные контракты Strategy

Единственный обязательный контракт:

```php
interface Strategy
{
    public function run(
        WorkEnvelope $work,
        Cancellation $cancellation,
    ): mixed;
}
```

Дополнительное поведение включается интерфейсами, а не конфигурационным god
object:

- `BootstrapsApplication` создаёт и закрывает process-local ресурсы;
- `UsesManagedTasks` получает новую отдельную для задачи группу;
- `ProvidesTaskExecutionPolicy` выбирает `Concurrent` или `Parallel` для каждой
  managed task; неизвестный task по умолчанию изолируется в child process;
- `ProvidesContainer` разрешает handlers через worker-local PSR-11 container;
- `ProvidesManagedTaskBootstrap` отдельно поднимает child runtime;
- `HandlesTaskFailure` возвращает ограниченный `RetryCurrent`, `CompleteAs`,
  `FailCurrent` или `AbortRun`;
- `RoutesChildApplicationEvents` решает, какие child events поднимать в root.

`boot()` происходит после запуска primary. `close()` должен быть idempotent и
вызывается также после частичного boot failure. При shutdown сначала закрывается
child pool, затем ресурсы стратегии: child не должен увидеть уже закрытое
соединение или parser.

## Структурированные managed tasks

Перед `run()` стратегия с `UsesManagedTasks` получает новую группу. `submit()`
выбирает executor через `TaskExecutionPolicy` и сразу возвращает `TaskHandle`.
Concurrent task выполняется Fiber-ом внутри primary с ограниченным числом slots. Parallel
task отправляется в переиспользуемый child pool.

Группа принадлежит одному WorkEnvelope:

1. Strategy может submit несколько задач и ждать отдельные handles.
2. После выхода из `run()` runtime всегда вызывает `join()`.
3. При ошибке стратегии группа отменяется и затем также join-ится.
4. После join новые submit запрещены.
5. Ни одна managed task не переживает родителя.

Если ошибка стратегии и освобождение ресурсов/join происходят одновременно,
`WorkerCleanupFailed` сохраняет primary failure и ошибки cleanup.

## События и корреляция

У каждой primary и managed task есть `EventContext`: `runId`, `WorkId`, taskId,
parentTaskId, роль процесса, depth и sourceId. `ProcessEvents::emit()` доступен
внутри активного task scope и отправляет `ApplicationEvent` в root. Runtime
добавляет PID, monotonic sequence внутри source и timestamp.

Root непрерывно дренирует event channel параллельно ожиданию результата, поэтому
worker не блокируется на заполненном канале. События жизненного цикла core и application
events разведены по observers. Ошибка observer не подменяет ошибку задачи и
попадает в `observerFailures()`.

## Ошибки и восстановление после потери процесса

Application failure означает, что primary жив и может выполнить
`HandlesTaskFailure`. Worker crash означает потерю процесса и обрабатывается
локальный для root `WorkerCrashPolicy`. Повтор после потери процесса разрешён только когда Strategy
явно реализует `DeclaresIdempotentWork` и подтверждает конкретный WorkEnvelope.

`FailureDiagnostic` безопасно переносится через IPC и хранит:

- failure kind и type/message/code/file/line;
- origin: role, executor, PID, WorkId, taskId и attempt;
- до 5 trace frames и до 3 previous causes;
- количество отброшенных frames/causes и признак усечения сообщения.

Аргументы, локальные переменные, payload, environment и container graph не захватываются.
Полная смерть runtime не восстанавливается библиотекой: durable dispatch и
решение о новом run принадлежат приложению или оператору.

## Отмена и освобождение ресурсов

Внешняя отмена объединяется с внутренней остановкой run. Новые задачи
перестают выдаваться, активные primary/child получают cancellation, root
продолжает собирать доступные outcomes и diagnostics. На совместное освобождение ресурсов
отводится `cancellationGrace`; затем зависший managed worker принудительно
останавливается.

Root hooks не пересекают process boundary. Они запускаются вокруг run, worker и
task lifecycle. Стратегия, payload, managed tasks, events и результаты должны
быть сериализуемыми; connections, streams, XMLReader, generators, closures,
Futures и готовые containers остаются process-local.

## Верхняя граница ресурсов

При `P` primary workers и `C` child workers на primary:

```text
до 1 + P + P*C OS-процессов
```

Fiber-конкурентность `F` не создаёт новые процессы, но увеличивает число
одновременных process-local операций. `P`, `C` и `F` выбираются вместе с CPU,
RAM, descriptors и пропускной способностью диска/БД. Глобального динамического
лимита child workers между несколькими primary пока нет.
