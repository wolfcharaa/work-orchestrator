# Разработка и история изменений

[English](CONTRIBUTING.en.md)

Каноническая история пакета находится в отдельном repository
`wolfcharaa/work-orchestrator`. Изменения приложения-потребителя оформляются в
его собственном repository и не смешиваются с изменениями публичного контракта
библиотеки.

## Границы коммитов

- Один commit содержит одну причину изменения публичного пакета.
- Интеграция с приложением-потребителем и обновление его lock-файла
  фиксируются отдельно в repository этого приложения.
- Performance checkpoint включает код benchmark и синхронизированные RU/EN
  результаты; сырые тяжёлые данные в Git не добавляются.
- Формат сообщения следует правилам родительского repository, например
  `FEAT(work-orchestrator): Добавлен process-local task pipeline`.
- Публичный API, документация и тесты одного контракта могут находиться в одном
  atomic commit. Несвязанный refactoring отделяется.

Такое разделение сохраняет независимые release и consumer histories.

## Выпуск

Release выполняется только из чистой `main` после синхронизации RU/EN
документации и успешных проверок. Версия определяется annotated Git tag вида
`v0.4.0`; Composer не хранит отдельное поле версии.

```bash
composer validate --strict
composer check
composer benchmark:worker-files -- --help
composer benchmark:record-pipeline -- --help
git tag -a v0.4.0 -m "Work Orchestrator 0.4.0"
```

Сначала публикуется проверенная `main`, затем тот же commit получает и публикует
tag. Packagist должен указывать на canonical GitHub repository; release не
считается доступным потребителю, пока `composer show fedor/work-orchestrator
--all` не видит новый tag.

## Проверки

Из package root:

```bash
composer check
composer benchmark:worker-files -- --help
composer benchmark:record-pipeline -- --help
```

Root application lock остаётся отдельной проверкой совместимости. Коммит
библиотеки не должен незаметно изменять его зависимости.
