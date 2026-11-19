# История изменений

[English](CHANGELOG.en.md)

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
