# Справочник публичного API

[English](../en/public-api.md) · [README](../../README.md)

Справочник сгруппирован по задачам потребителя. Он объясняет роль и границу
компонента; сигнатуры и generic-типы остаются в PHPDoc исходного кода. Базовые
сценарии сначала лучше пройти по [началу работы](getting-started.md).

## Composition, идентичность и запуск

| Компонент | Роль и место выполнения | Нормальное использование и ограничение |
| --- | --- | --- |
| `Orchestrator` | Одноразовый root coordinator | Создать через `fromQueue()`, настроить до `run()`, получить `RunResult`; повторная настройка/запуск отклоняется |
| `Strategy<TPayload,TResult>` | Пользовательская политика обработки в primary | Состояние конструктора сериализуемо; `run()` получает один `WorkEnvelope` и сигнал отмены; I/O, транзакции и смысл результата принадлежат приложению |
| `WorkEnvelope<TPayload>` | Сериализуемая пара identity + данных задачи | Передаётся из root в primary; payload должен совпадать с типом Strategy и оставаться небольшим |
| `WorkId` | Устойчивая identity одной root-задачи | Должен быть непустым и уникальным в очереди; связывает hooks, события, результаты и диагностику |

Конфигурация `Orchestrator` до запуска:

| Метод | Default | Смысл |
| --- | ---: | --- |
| `primaryWorkers()` | 1 | Лимит одновременно активных primary processes |
| `childWorkersPerPrimary()` | 1 | Ленивые managed workers, логически связанные с primary; каждый остаётся прямым child root |
| `concurrentTasksPerPrimary()` | 1 | Concurrent Fiber slots каждого primary |
| `maxIpcPayloadBytes()` | 1 MiB | Лимит сериализованного управляющего сообщения |
| `cancellationGrace()` | 5.0 s | Совместное освобождение ресурсов до принудительной остановки |
| `forceKillAfter()` | 2.0 s | Время после TERM до KILL всё ещё работающего worker |
| `workerOutputTailBytes()` | 16 KiB | Ограниченный хвост stdout и stderr каждого worker для диагностики |
| `workerAutoloader()` | auto | Явный путь к Composer autoloader, когда auto-discovery не подходит |
| `workerCrashPolicy()` | abort | Root policy потери primary; retry требует отдельной декларации идемпотентности |

Методы `beforeRun`, `onWorkerLifecycle`, `beforeTask`, `onTaskLifecycle`,
`onApplicationEvent`, `afterTask`, `afterRun`, `onObserverFailure` добавляют
локальные для root hooks, описанные ниже.

## Admission и очереди

| Компонент | Роль | Использование / неправильное применение |
| --- | --- | --- |
| `WorkQueue<TPayload>` | Продвинутый локальный для root контракт расширения | Реализуйте только для новой политики допуска; очередь конечна, count стабилен, разрешения завершаются точно один раз |
| `OrderedWorkQueue` | Готовая queue независимых items | Выдаёт по порядку без completion barrier; порядок результата сохраняется, порядок окончания нет |
| `GroupedWorkQueue` | Готовые строгие стадии + независимый запас | Следующая группа ждёт завершения текущей; не помещайте зависимую работу в opportunistic lane |
| `AdmissionDecision<T>` | Marker результата `acquire()` | Consumer queue возвращает один из трёх concrete вариантов |
| `Granted<T>` | Разрешение выполнить item | Содержит `TaskLease`; root передаёт данные задачи worker |
| `Blocked` | Временная невозможность допуска | Содержит ожидаемый readiness-сигнал; future должен завершиться при изменении состояния, иначе возникнет deadlock, а уже завершённый future создаст цикл без ожидания |
| `Exhausted` | Необратимое исчерпание | После него queue не обещает новых items |
| `TaskLease<T>` | Atomic grant: ID, result position, work | Queue обязана принять тот же объект в `complete()` ровно один раз; forged/equal-copy lease отклоняется |
| `AdmissionPriority` | Строгий приоритет либо независимое значение | Обычно создаётся `strict(n)`/`opportunistic()` для группировки файлов |

`Scheduling\AbstractTaskQueue` — `@internal`; это реализация общего состояния
встроенных queues, а не base class потребителя. Полный публичный протокол и
пример находятся в [руководстве по очередям](work-queues.md).

## Файловый адаптер

| Компонент | Роль и ownership | Нормальное использование |
| --- | --- | --- |
| `FileWorkQueue` | Root-side factory `WorkQueue<FileEntry>` | `discover()` сканирует paths; `ordered()` принимает готовые entries; grouped-варианты добавляют barriers |
| `FileEntry` | Сериализуемые данные задачи с canonical path и размером preflight | Worker открывает/читает файл по path; size — снимок root, а не блокировка файла |
| `FileOrder` | Локальный для root comparator до старта workers | `pathAscending`, `largestFirst`, `smallestFirst`, `custom`; меняет порядок допуска, но не добавляет barrier |
| `FilePriority` | Локальный для root классификатор strict/opportunistic | `fromBasenamePatterns()` либо `custom()`; конфликт двух строгих приоритетов отклоняется |
| `FileDiscoveryFailed` | Ошибка чтения metadata/discovery input | Ловите вокруг composition, если нужен собственный UX до старта run |
| `InvalidReadRequest` | Некорректный file input/pattern request | Исправляется вызывающим кодом; это не task outcome |

Типы `File\Internal` не являются публичными extension points.

## Capability-интерфейсы Strategy

Capability включается реализацией интерфейса; отдельного registry нет.

| Интерфейс | Кто вызывает / где | Что обязан сделать consumer |
| --- | --- | --- |
| `BootstrapsApplication` | Runtime в каждом primary: `boot()` один раз, `close()` при shutdown | Создать process-local resources; сделать `close()` идемпотентным |
| `UsesManagedTasks` | Runtime перед каждым root item | Сохранить новый отдельный для задачи `ManagedTaskGroup` только для текущего `run()` |
| `ProvidesTaskExecutionPolicy` | Primary перед submit | Вернуть `TaskExecutionPolicy`, выбирающий Concurrent/Parallel |
| `ProvidesContainer` | Primary handler resolver | Вернуть уже bootstrapped worker-local PSR-11 container; не сериализовать container из root |
| `ProvidesManagedTaskBootstrap` | Child runtime | Вернуть сериализуемый `ManagedTaskBootstrap` recipe для child-local container |
| `ProvidesManagedTaskCrashPolicy` | Primary при потере child | Вернуть ограниченный `ManagedTaskCrashPolicy`; retry требует `IdempotentManagedTask` |
| `HandlesTaskFailure` | Primary при application exception | Задать positive max attempts и вернуть один `FailureAction` |
| `DeclaresIdempotentWork` | Root перед retry после потери primary | Явно подтвердить конкретный `WorkEnvelope`; ложное подтверждение может дублировать side effect |
| `RoutesChildApplicationEvents` | Primary для application event из child | Вернуть `Relay` в root или `Consume` локально |

Обычная `Strategy` не должна реализовывать capabilities, которые ей не нужны.
Каждый дополнительный контракт добавляет обязанности жизненного цикла и обработки ошибок.

## Managed tasks

| Компонент | Роль | Контракт |
| --- | --- | --- |
| `ManagedTask` | Маленькое сериализуемое сообщение | `handler()` возвращает class-string `ManagedTaskHandler` |
| `ManagedTaskHandler` | Исполнитель одного сообщения внутри процесса | `handle()` соблюдает отмену и возвращает сериализуемое значение |
| `ManagedTaskGroup` | Структурированный владелец подзадач одного item | `submit()` возвращает handle; `join()` ждёт все задачи и закрывает группу для новых submit |
| `TaskHandle` | Ожидаемый результат одного submit | `outcome()` возвращает журнал; `value()` бросает `ManagedTaskFailed` при failure |
| `TaskExecutionPolicy` | Выбор executor для каждой task | `modeFor()` возвращает `ExecutionMode` |
| `ExecutionMode::Concurrent` | Fiber в primary | Для неблокирующего I/O и безопасно разделяемых primary-local services |
| `ExecutionMode::Parallel` | Child OS-process | Default; для CPU/blocking работы или process isolation |
| `ManagedTaskBootstrap` | Жизненный цикл service graph внутри child | `boot()` возвращает PSR-11 container, `close()` освобождает ресурсы |
| `ManagedTaskCrashPolicy` | Recovery потери child | `maxAttempts()` + `onCrash()` → `WorkerCrashAction` |
| `IdempotentManagedTask` | Marker безопасного crash retry | Реализуйте только если повтор после неизвестного момента сбоя безопасен |
| `ChildEventRoute` | Решение primary для child event | `Relay` доставляет root, `Consume` останавливает маршрут |

## Failure policy

| Компонент | Значение и последствие |
| --- | --- |
| `FailureAction` | Marker результата `HandlesTaskFailure::onFailure()` |
| `RetryCurrent` | Повторяет текущий item в живом primary после необязательной неотрицательной delay |
| `CompleteAs` | Считает item успешным с заданным value; используйте только для осознанного fallback |
| `FailCurrent` | Завершает item неуспешно, не запрашивая retry |
| `AbortRun` | Завершает item и инициирует остановку всего run |
| `WorkerCrashPolicy` | Root-local policy потери primary: max attempts и действие на crash |
| `WorkerCrashAction::AbortRun` | Не повторяет работу на новом процессе; default |
| `WorkerCrashAction::RetryOnFreshWorker` | Создаёт/использует новый primary только при `DeclaresIdempotentWork=true` |

Application failure и process crash — разные сигналы. Не используйте crash
retry как замену транзакциям или durable deduplication.

## Hooks

| Value / interface | Что доступно observer |
| --- | --- |
| `RunContext` | run ID, total task count, primary limit, startedAt |
| `RunSummary` | status, results, diagnostics, timestamps, `durationSeconds()` |
| `TaskBoundary<T>` | run/task IDs, `WorkEnvelope`, позиция допуска, необязательный terminal outcome |
| `WorkerLifecycle` | Общий `workerId()` для start/stop variants |
| `WorkerStarted` | Worker появился |
| `WorkerStopped` | Worker остановлен; `clean` отличает штатное завершение |
| `ObserverFailure` | Фаза и исходный локальный для root `Throwable` observer |

Эти значения остаются в root и не обязаны быть serializable.

## События

| Компонент | Роль и использование |
| --- | --- |
| `ApplicationEvent` | Marker маленького пользовательского serializable event |
| `ProcessEvents` | Consumer вызывает только `emit(ApplicationEvent)` внутри активной task; остальные методы жизненного цикла помечены `@internal` |
| `EventEnvelope` | Correlation + `CoreEvent|ApplicationEvent`; `isTaskLifecycle()` различает жизненный цикл core |
| `EventContext` | Runtime correlation value; обычно читается через envelope, не создаётся consumer |
| `CoreEvent` | Marker событий runtime |
| `TaskLifecycleEvent` | Marker core attempt events |
| `TaskStarted` | Executor и номер начавшейся попытки |
| `TaskSucceeded` | Executor и номер успешной попытки |
| `TaskFailed` | Executor, attempt, failure type/message |
| `RetryScheduled` | Executor, номер следующей попытки и delay |
| `ProcessRole` | `Primary` или `Child` — где event возник |
| `ExecutorType` | `Primary`, `Concurrent` или `Parallel` — какой executor выполнял task |

## Результаты и diagnostics

| Компонент | Роль | Как читать |
| --- | --- | --- |
| `RunResult<TResult>` | Полный итог run | Сначала `status`, затем results/diagnostics; `throwIfFailed()` опционален |
| `WorkResult<TResult>` | Итог одного admitted `WorkId` | `outcome` не бросает; `value()` бросает `WorkFailed` при failure |
| `TaskOutcome<TResult>` | Terminal status/value/failure + non-terminal diagnostics | Создание через `succeeded/failed/cancelled`; queue получает его в `complete()` |
| `RunStatus` | `Succeeded`, `Failed`, `Cancelled` | Статус всего run, не отдельного item |
| `TaskStatus` | `Succeeded`, `Failed`, `Cancelled` | Статус одного root/managed item |
| `FailureDiagnostic` | Bounded serializable failure evidence | Анализируйте `kind`, `origin`, type/message и truncation counters |
| `FailureKind` | Stable category | Application, Cancellation, WorkerCrash, Bootstrap, Cleanup, Transport, Observer, ForcedTermination |
| `FailureOrigin` | Typed coordinates | role/executor/PID/WorkId/task/attempt; PID 0 означает unavailable process |
| `FailureCause` | Bounded previous-exception snapshot | Support value внутри diagnostic, не exception для catch |
| `TraceFrame` | Safe structural trace frame | Не содержит arguments/locals |

## Исключения

Большинство ошибок выполнения попадает в итоговый журнал. Исключения также нужны для
ошибки composition или явного value/exception flow:

| Exception | Когда встречается / действие consumer |
| --- | --- |
| `InvalidRunRequest` | Пустая/дублирующая queue, invalid limit, reuse orchestrator/lease; исправить composition |
| `InvalidManagedTask` | Handler class нельзя создать/получить из container либо тип неверен; исправить task wiring |
| `IpcPayloadExceeded` | Serialized control message больше лимита; уменьшить сообщение |
| `ProcessEventScopeUnavailable` | `ProcessEvents::emit()` вне Strategy/handler scope; перенести emit внутрь task |
| `WorkFailed` | Запрошен `WorkResult::value()` для failure; читать `$diagnostic` |
| `RunFailed` | Вызван `throwIfFailed()` для failed/cancelled run; читать `$result` |
| `ManagedTaskFailed` | Запрошен `TaskHandle::value()` для failure; читать diagnostic/attempt diagnostics |
| `ManagedTaskGroupFailed` | Group join обнаружил несколько failures; читать `failures` и `diagnostics` |
| `WorkerBootstrapFailed` | Primary не стартовал или Strategy boot упал; причина в diagnostic/previous |
| `WorkerCleanupFailed` | Освобождение ресурсов упало; хранит primary failure и список cleanup failures |
| `RemoteTaskFailure` | Служебное исключение runtime, реконструирующее удалённую причину отмены; не используйте как extension point |
| `FileDiscoveryFailed` / `InvalidReadRequest` | File composition не смогла построить manifest; исправить inputs/access/patterns до run |

## Что не является публичным контрактом

Все namespaces `Internal`, `File\Internal`, методы `ProcessEvents`, помеченные
`@internal`, и `Scheduling\AbstractTaskQueue` могут меняться без consumer
compatibility. Если публичных contracts недостаточно, зафиксируйте use case как
API gap вместо зависимости от internal type.
