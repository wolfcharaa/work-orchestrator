# История изменений

[English](CHANGELOG.en.md)

## 1.0.0 — не выпущена

- Backend `amphp/parallel` заменён direct process graph под полным владением
  root: каждый primary и managed worker запускается, финализируется,
  останавливается и reap-ится root через отдельные framed pipes.
- Сохранены публичные контракты finite work, managed tasks, cancellation,
  events, results и recovery; минимальная версия PHP снижена до 8.3.
- Production async foundation ограничен `amphp/amp` и `revolt/event-loop`;
  библиотека сама владеет `proc_open`, framing протокола, дренированием
  stdout/stderr и эскалацией TERM → KILL.
- Добавлены discovery и override Composer autoloader для чистых workers,
  bounded output tails, ленивые managed pools и совместимость с уже активным
  Revolt loop.
- Добавлен чистый PHP 8.3 consumer fixture, проверяющий primary и managed
  execution и точный production dependency closure из пяти пакетов.

## 0.4.1 — 2026-11-19

- Публичная документация перестроена вокруг задач читателя: добавлены
  определения, наблюдаемый quick start, руководства по queues, managed tasks,
  lifecycle/recovery и сгруппированный справочник API.
- Добавлены воспроизводимые examples для generic work, managed child tasks,
  application events и пользовательской `WorkQueue`, а record-import получил
  fixture, команду запуска и ожидаемый результат.
- Пакет теперь публикует интерактивный AI skill `work-orchestrator`; для локальной
  разработки подключён `llm/skills`, а skill синхронизируется из
  `resources/skills`.
- Исправлено описание provenance `ext-uv` в benchmark-документации без изменения
  production dependencies или runtime API.

## 0.4.0 — 2026-11-19

- Выпущена самостоятельная библиотека `fedor/work-orchestrator` для конечных
  concurrent/parallel очередей с ограниченной process-топологией.
- Добавлены generic `WorkQueue`, `Strategy`, strict-группы и opportunistic
  admission без привязки ядра к файловому формату.
- Добавлены reusable managed child workers, process-local bootstrap,
  cancellation, lifecycle hooks и bounded structured diagnostics.
- `GroupedWorkQueue` гарантирует выдачу всех siblings открытой strict-группы до
  перехода к следующему барьеру.
- Переход с `fedor/async-parser` является breaking rename без compatibility
  aliases; migration path описан в README.
