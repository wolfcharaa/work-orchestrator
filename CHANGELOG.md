# История изменений

[English](CHANGELOG.en.md)

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
