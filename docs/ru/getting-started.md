# Начало работы

[English](../en/getting-started.md) · [README](../../README.md)

После этого руководства вы сможете запустить две конечные задачи, увидеть их
результат и понять, какие объекты остаются в root-процессе, а какие Composer
должен загрузить в worker.

## Определения

| Термин | Что означает в этой библиотеке |
| --- | --- |
| конечный набор | Очередь заранее знает общее число задач; ожидания новых сообщений после исчерпания нет |
| root | Процесс, вызвавший `Orchestrator::run()`; только здесь живут очередь и hooks |
| primary worker | Процесс первого уровня с собственной копией `Strategy` |
| child worker | Процесс второго уровня для управляемой `ManagedTask`; глубже библиотека процессы не создаёт |
| admission (допуск) | Ответ `WorkQueue::acquire()`: задача выдана, временно заблокирована или очередь исчерпана |
| payload (данные задачи) | Пользовательский объект внутри `WorkEnvelope`; это рецепт работы, а не большой массив данных |
| lease (разрешение) | Уникальный `TaskLease`, связывающий выдачу задачи с её единственным завершением |
| IPC | Inter-process communication — межпроцессная передача сериализованных задач, событий и результатов |
| process-local | Объект создан и используется внутри одного процесса; соединение, stream или parser не передаётся через IPC |
| cancellation (отмена) | Сигнал остановить новый допуск и дать активной работе совместно завершиться |
| idempotency (идемпотентность) | Повтор той же работы не создаёт недопустимого повторного эффекта |

Названия PHP-классов и методов не переводятся: это точные идентификаторы API.

## Первый наблюдаемый результат

В репозитории уже есть два сообщения `RebuildCustomer`, стратегия и запускаемый
скрипт. Установите зависимости и выполните:

```bash
composer install
composer example:basic
```

Входные данные зафиксированы в [`examples/basic.php`](../../examples/basic.php):
customer `42` и `77`. Ожидаемый вывод:

```text
customer:42 => 42
customer:77 => 77
run => succeeded
```

Что произошло:

1. `OrderedWorkQueue` сохранил две задачи в заданном порядке.
2. Каждый `WorkEnvelope` связал стабильный `WorkId` и небольшой payload
   `RebuildCustomer`.
3. Root создал не более двух primary workers и передал им сериализуемые задачи.
4. `RebuildCustomerStrategy::run()` проверил cancellation и вернул customer ID.
5. `RunResult::results()` вернул исходы в порядке допуска, даже если workers
   завершили работу в другом порядке.

Полезная логика находится в
[`RebuildCustomerStrategy`](../../examples/Basic/RebuildCustomerStrategy.php),
а composition — в скрипте. Комментарий внутри стратегии обозначает место, где
приложение обычно загружает модель и выполняет действие; библиотека этого не
делает.

## Перенос в приложение

Классы, пересекающие границу процесса, должны быть доступны через Composer
autoload. Типичная структура приложения:

```text
src/
  Work/RebuildCustomer.php
  Work/RebuildCustomerStrategy.php
bin/
  rebuild-customers.php
```

При стандартном mapping `"App\\": "src/"` классы получают namespace
`App\Work`. После добавления файлов обновите autoloader (`composer dump-autoload`).
Не объявляйте payload или `Strategy` только внутри `bin/*.php`: новый PHP-процесс
загружает Composer autoloader, а не исполняет родительский entry script.

Root-композиция остаётся короткой:

```php
$queue = new OrderedWorkQueue([
    new WorkEnvelope(new WorkId('customer:42'), new RebuildCustomer(42)),
    new WorkEnvelope(new WorkId('customer:77'), new RebuildCustomer(77)),
]);

$result = Orchestrator::fromQueue($queue)
    ->primaryWorkers(2)
    ->run(new RebuildCustomerStrategy());
```

`WorkId` должен быть уникальным внутри очереди и устойчиво обозначать работу.
Он используется в hooks, events, diagnostics и точечном чтении результата.

## Что можно сериализовать

Через IPC проходят `Strategy`, `WorkEnvelope` и payload, managed tasks,
application events и результаты. Передавайте в них простые значения и DTO:
идентификаторы, пути, ключи, параметры запроса, безопасные диапазоны.

Не передавайте открытые соединения, streams, `XMLReader`, generators, closures,
`Future` или собранный DI container. Создавайте их внутри primary через
`BootstrapsApplication`; это разобрано в [управляемых задачах](managed-tasks.md).
Лимит `maxIpcPayloadBytes()` по умолчанию равен 1 MiB и относится к
control-plane сообщениям, а не только к пользовательскому payload.

## Чтение результата

`run()` обычно возвращает полный журнал исходов, а не бросает исключение за каждую ошибку:

```php
if ($result->status === RunStatus::Succeeded) {
    $customerId = $result->value(new WorkId('customer:42'));
}

foreach ($result->results() as $workResult) {
    $outcome = $workResult->outcome; // status, value, failure, diagnostics
}
```

- `values()` возвращает только успешные значения в порядке допуска;
- `result($id)` возвращает `null`, если ID не найден, и не бросает исключение;
- `value($id)` бросает `WorkFailed` для неуспешного исхода и
  `OutOfBoundsException` для неизвестного ID;
- `throwIfFailed()` переводит весь run в exception flow и бросает `RunFailed`,
  если статус не `succeeded`.

Для частичных ошибок сначала читайте журнал исходов и диагностику. Подробная
матрица поведения находится в [руководстве по наблюдаемости и восстановлению](observability-and-recovery.md).

## Следующий выбор

- Нужен простой список, зависимые стадии или файлы — переходите к
  [очередям и барьерам](work-queues.md).
- Одна root-задача должна породить несколько ограниченных подзадач — к
  [управляемым задачам](managed-tasks.md).
- Нужны progress events, hooks или обработка ошибок — к
  [наблюдаемости и восстановлению](observability-and-recovery.md).
