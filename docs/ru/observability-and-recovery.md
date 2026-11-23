# Наблюдаемость, ошибки и восстановление

[English](../en/observability-and-recovery.md) · [README](../../README.md)

Библиотека разделяет локальные для root hooks, события из workers и итоговый журнал.
Hooks подходят для метрик и внешнего логирования в исходном процессе; events —
для коррелированных фактов из выполняемой задачи; `RunResult` — источник истины
после завершения run.

## Воспроизводимый сценарий жизненного цикла

Запустите:

```bash
composer example:observability
```

Ожидаемый вывод для одной задачи:

```text
run: start tasks=1
task: admit customer:42
lifecycle: customer:42 started attempt=1
application: rebuilt customer=42
lifecycle: customer:42 succeeded attempt=1
task: complete customer:42 status=succeeded
run: finish status=succeeded
```

[`observability.php`](../../examples/observability.php) регистрирует observers в
root. [`ObservedCustomerStrategy`](../../examples/Observability/ObservedCustomerStrategy.php)
в primary вызывает `ProcessEvents::emit()`, а библиотека добавляет correlation и
доставляет [`CustomerRebuilt`](../../examples/Observability/CustomerRebuilt.php)
в `onApplicationEvent`. Это показывает реальную границу IPC, а не ручной вызов
observer из того же процесса.

## Root-local hooks

Closure hook никогда не сериализуется в worker. Поэтому он может захватить
локальный logger или metrics client root-процесса, но не должен выполнять тяжёлую обработку,
от которой зависит полезная работа.

| Метод | Аргумент | Когда использовать |
| --- | --- | --- |
| `beforeRun` | `RunContext` | run ID, число задач, лимит primary и время старта |
| `onWorkerLifecycle` | `WorkerStarted` / `WorkerStopped` | число живых workers и чистота остановки |
| `beforeTask` | `TaskBoundary` без outcome | факт admission root-задачи и её позиция |
| `onTaskLifecycle` | `EventEnvelope` с `TaskStarted`, `TaskSucceeded`, `TaskFailed`, `RetryScheduled` | попытки primary/managed tasks |
| `onApplicationEvent` | `EventEnvelope` с пользовательским `ApplicationEvent` | progress и доменные/технические факты приложения |
| `afterTask` | `TaskBoundary` с `TaskOutcome` | терминальный исход допущенной root-задачи |
| `afterRun` | `RunSummary` | статус, длительность, результаты и diagnostics всего run |
| `onObserverFailure` | `ObserverFailure` | отдельный канал ошибок других observers |

Ошибка observer не подменяет исход задачи. Она сохраняется в
`RunResult::observerFailures()` и передаётся `onObserverFailure`. Сам
`onObserverFailure` должен быть особенно простым: это последний защитный канал.

## Application events

Пользовательский event — небольшой сериализуемый объект, реализующий
`ApplicationEvent`. Внутри `Strategy`, handler или вызванного ими сервиса:

```php
ProcessEvents::emit(new RowsParsed($count));
```

Вне активной task scope вызов бросает `ProcessEventScopeUnavailable`. Не
передавайте record batches или модели: `maxIpcPayloadBytes()` ограничивает event,
а полный канал не должен становиться data plane.

`EventEnvelope` добавляет `runId`, `WorkId`, `taskId`, `parentTaskId`, роль
процесса, depth, source ID, монотонный sequence в рамках source, timestamp и
PID. Сравнивайте sequence только внутри одного `sourceId`; глобальный порядок
между workers не обещан.

## Результат без потери частичного прогресса

`RunResult` возвращается для `succeeded`, `failed` и `cancelled`:

- `results()` — терминальные `WorkResult` уже допущенных items в порядке допуска;
- `values()` — только успешные values;
- `result(WorkId)` — nullable доступ без исключения;
- `value(WorkId)` — value либо `WorkFailed` с тем же `FailureDiagnostic`;
- `diagnostics()` — ошибки задач, повторов, bootstrap/cleanup/transport;
- `observerFailures()` — изолированные ошибки root observers;
- `summary` — тот же итоговый `RunSummary`;
- `throwIfFailed()` — опциональный `RunFailed`, который сохраняет исходный
  `RunResult` в `$exception->result`.

`TaskOutcome` содержит `TaskStatus`, value, terminal failure и diagnostics
предыдущих попыток. Не извлекайте `value()` вслепую из частично неуспешного run,
если нужно обработать все items: сначала проверяйте `outcome`.

## Что хранит диагностика

`FailureDiagnostic` — ограниченный сериализуемый снимок, а не удалённый
`Throwable`. Он содержит kind, type/message/code/file/line, координаты origin
(role, executor, PID, `WorkId`, task ID, attempt), до пяти trace frames и трёх
previous causes, а также счётчики усечения.

Arguments, locals, payload, environment и container graph намеренно не
захватываются. Поэтому diagnostics безопаснее переносить через IPC, но она не
заменяет application logging с нужными вам полями.

Kinds: `application`, `cancellation`, `worker_crash`, `bootstrap`, `cleanup`,
`transport`, `observer`, `forced_termination`.

## Матрица ошибок и владельцев восстановления

| Событие | Что остаётся после него | Кто решает восстановление |
| --- | --- | --- |
| `Strategy::run()` бросила исключение, primary жив | `HandlesTaskFailure` получает throwable и номер попытки | стратегия возвращает `RetryCurrent`, `CompleteAs`, `FailCurrent` или `AbortRun` |
| Primary process исчез | diagnostic `worker_crash`; неизвестно, завершился ли side effect | root `WorkerCrashPolicy`; retry только вместе с `DeclaresIdempotentWork` для конкретного item |
| Managed handler бросил | неуспешный managed `TaskOutcome` | стратегия читает handle или даёт группе завершиться; подробности в managed-tasks guide |
| Child process исчез | diagnostic `worker_crash` | `ManagedTaskCrashPolicy`; retry только для `IdempotentManagedTask` |
| Bootstrap или cleanup упал | run/work получает bounded diagnostics; cleanup может сохранить primary cause | приложение исправляет lifecycle; автоматический новый run не создаётся |
| Observer бросил | useful work продолжается, ошибка отдельно в `observerFailures()` | root monitoring code |
| IPC message больше лимита | `transport` diagnostic до небезопасной отправки | уменьшить payload/event/result и открыть bulk resource локально |
| Внешняя cancellation | новый admission прекращается, активные задачи получают cancellation, статус становится `cancelled` когда отмена является причиной остановки | вызывающий код и cooperative cleanup |
| Весь runtime умер | in-memory `RunResult` может отсутствовать | durable application state и оператор; библиотека не делает resume |

## Два разных retry

`HandlesTaskFailure` работает, когда primary **жив** и известно, что вызов
стратегии завершился исключением. Его `maxAttempts()` ограничивает общее число
попыток. `RetryCurrent($delay)` повторяет item в том же primary;
`CompleteAs($value)` превращает известную ошибку в успешный value;
`FailCurrent` завершает только item; `AbortRun` останавливает run.

`WorkerCrashPolicy` работает, когда процесс **исчез** и точный момент side effect
неизвестен. Default policy abort-ит run. `RetryOnFreshWorker` допустим только
если `DeclaresIdempotentWork::isIdempotent($work)` возвращает `true`; иначе
runtime отклоняет небезопасный retry.

Задержки и retries существуют только в памяти текущего run. Для durable retry
после рестарта храните intent/checkpoint в приложении и создавайте новый run.

## Отмена и освобождение ресурсов

Переданный в `run($strategy, $cancellation)` сигнал объединяется с внутренней
остановкой. Strategy и handlers должны периодически вызывать
`throwIfRequested()` или передавать cancellation в async операции. Root ждёт
совместное освобождение ресурсов не более `cancellationGrace()` (по умолчанию
5 секунд), затем посылает TERM. Если worker продолжает работать после
`forceKillAfter()` (по умолчанию 2 секунды), root посылает KILL и reap-ит его.
stdout и stderr отделены от protocol traffic; для каждого worker сохраняется не
более `workerOutputTailBytes()` (по умолчанию 16 KiB) каждого stream.

`close()` освобождает ресурс, но не является местом для нового большого объёма
работы. Делайте освобождение ресурсов ограниченным и идемпотентным; отдельно проектируйте транзакции и
компенсацию частичных side effects.

Связанные страницы: [управляемые задачи](managed-tasks.md),
[runtime-протокол](worker-pipeline.md), [справочник API](public-api.md).
