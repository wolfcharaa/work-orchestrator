# Process-local ресурсы и управляемые задачи

[English](../en/managed-tasks.md) · [README](../../README.md)

Эта страница отвечает на два разных вопроса: как один primary worker
переиспользует тяжёлый ресурс и как одна root-задача безопасно делится на
ограниченные подзадачи. Managed task не добавляет новую root work item и не
переживает родительскую `Strategy::run()`.

## Когда достаточно одной Strategy

Начинайте с обычной `Strategy`. Если данные задачи можно обработать одним вызовом и
нет независимых подзадач, managed tasks не нужны. `primaryWorkers(n)` уже
позволяет одновременно обрабатывать до `n` root items в отдельных процессах.

Если стратегии нужен parser, соединение с БД или DI container, реализуйте
`BootstrapsApplication`:

```php
final class ImportStrategy implements BootstrapsApplication
{
    private ?DatabaseConnection $connection = null;

    public function boot(Cancellation $cancellation): void
    {
        $this->connection = DatabaseConnection::connect(/* serializable config */);
    }

    public function run(WorkEnvelope $work, Cancellation $cancellation): mixed
    {
        $connection = $this->connection
            ?? throw new LogicException('Strategy is not bootstrapped.');

        // use the process-local connection
    }

    public function close(): void
    {
        $this->connection?->close();
        $this->connection = null; // makes repeated close safe
    }
}
```

Конструктор стратегии содержит только сериализуемый рецепт подключения.
`boot()` вызывается один раз после старта каждого primary, `run()` — для каждой
назначенной ему работы, `close()` — при завершении, в том числе после частичного
boot failure. Поэтому `close()` должен быть идемпотентным.

## Воспроизводимый пример managed tasks

Пример считает квадраты `1, 2, 3, 4` в child workers и складывает значения в
primary:

```bash
composer example:managed-tasks
```

Ожидаемый вывод:

```text
numbers => 1, 2, 3, 4
sum of squares => 30
run => succeeded
```

Код разделён по ролям:

- [`NumberBatch`](../../examples/ManagedTasks/NumberBatch.php) — данные одной
  root-задачи;
- [`SquareNumber`](../../examples/ManagedTasks/SquareNumber.php) — маленькое
  сериализуемое сообщение подзадачи и имя handler;
- [`SquareNumberHandler`](../../examples/ManagedTasks/SquareNumberHandler.php) —
  полезная работа внутри выбранного executor;
- [`SumSquaresStrategy`](../../examples/ManagedTasks/SumSquaresStrategy.php) —
  получает отдельную для этой задачи группу, отправляет подзадачи и собирает значения;
- [`managed-tasks.php`](../../examples/managed-tasks.php) — root composition и
  limits.

Это один сценарий, а не набор независимых API snippets: классы из `examples/`
загружаются Composer и тем самым воспроизводят реальную границу процессов.

## Жизненный цикл группы

Стратегия включает capability `UsesManagedTasks`. Перед каждым `run()` runtime
передаёт ей новый `ManagedTaskGroup` через `useManagedTasks()`:

```php
$first = $tasks->submit(new SquareNumber(3));
$second = $tasks->submit(new SquareNumber(4));

$sum = $first->value($cancellation) + $second->value($cancellation);
```

`submit()` немедленно возвращает `TaskHandle`. `value()` ждёт исход и бросает
`ManagedTaskFailed`, если подзадача неуспешна; `outcome()` позволяет проверить
`TaskOutcome` без такого перехода. Явный `join()` полезен, когда нужны только
побочные эффекты, но runtime в любом случае join-ит все подзадачи после выхода
из `run()`. После join новые submit запрещены.

Если стратегия падает, группа отменяется и также join-ится. Root не начинает
финализацию процессов, пока managed work не settled. Process-local close hooks
primary и managed workers независимы, поэтому consumer не должен рассчитывать
на порядок между ними.

## Concurrent или Parallel

`ProvidesTaskExecutionPolicy` возвращает `TaskExecutionPolicy`, который выбирает
режим для каждой `ManagedTask`:

| `ExecutionMode` | Где выполняется | Когда выбирать | Главный риск |
| --- | --- | --- | --- |
| `Concurrent` | Fiber в том же primary | Асинхронный I/O, которому безопасно делить process-local container | CPU-bound или blocking код заблокирует весь primary |
| `Parallel` | Переиспользуемый child OS-process | CPU/blocking работа или нужна изоляция процесса | IPC и отдельная инициализация ресурсов |

Неизвестная task по умолчанию получает `Parallel`, то есть более сильную
изоляцию. `concurrentTasksPerPrimary(n)` ограничивает Fiber slots внутри каждого
primary; `childWorkersPerPrimary(n)` — ленивый managed pool. Managed workers
имеют логическую depth 2, но остаются прямыми OS-children root, который отвечает
за их остановку и reap. При `P` primary и `C` child верхняя граница —
`1 + P + P*C` процессов.

## Как создаётся handler

`ManagedTask::handler()` возвращает class-string `ManagedTaskHandler`. Runtime:

1. спрашивает worker-local PSR-11 container, если стратегия предоставляет его
   через `ProvidesContainer` (для Concurrent);
2. спрашивает container, созданный `ManagedTaskBootstrap` (для Parallel);
3. иначе создаёт handler с публичным конструктором без обязательных аргументов.

`ProvidesManagedTaskBootstrap` возвращает сериализуемый recipe. Его `boot()`
выполняется внутри каждого child и возвращает container, а `close()` освобождает
child-local ресурсы. Не передавайте уже собранный container из primary в child.

## Ошибка и исчезновение child

Обычное исключение handler превращается в неуспешный `TaskOutcome`; чтение
`TaskHandle::value()` бросает `ManagedTaskFailed`. Исчезновение child process —
другая граница: повтор разрешается через `ProvidesManagedTaskCrashPolicy`, но
сама task должна реализовать marker `IdempotentManagedTask`. Без явного
утверждения идемпотентности runtime не повторит работу после потери процесса,
потому что неизвестно, успел ли старый child создать побочный эффект.

`RoutesChildApplicationEvents` позволяет primary перехватить application event
из child и вернуть `ChildEventRoute::Consume` либо `Relay`. `Relay` отправляет
envelope дальше root observer; `Consume` оставляет решение внутри primary.

## Ограничения, которыми вы управляете

| Метод `Orchestrator` | По умолчанию | Значение |
| --- | ---: | --- |
| `primaryWorkers(n)` | 1 | Одновременно активные primary processes |
| `childWorkersPerPrimary(n)` | 1 | Ленивые reusable managed workers, связанные с primary |
| `concurrentTasksPerPrimary(n)` | 1 | Fiber slots на один primary |
| `maxIpcPayloadBytes(n)` | 1 MiB | Верхняя граница сериализованного task/event/result control message |
| `cancellationGrace(seconds)` | 5.0 | Время совместного освобождения ресурсов до принудительной остановки |
| `forceKillAfter(seconds)` | 2.0 | Время после TERM до KILL |
| `workerOutputTailBytes(bytes)` | 16 KiB | Сохраняемый хвост stdout/stderr каждого worker |
| `workerAutoloader(path)` | auto | Override Composer autoloader для чистых workers |

Выбирайте лимиты по CPU, RAM, file descriptors и пропускной способности
БД/диска. Глобального child budget между несколькими primary нет.

Связанные страницы: [runtime-протокол](worker-pipeline.md),
[наблюдаемость и восстановление](observability-and-recovery.md),
[справочник API](public-api.md).
