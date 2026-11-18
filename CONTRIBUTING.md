# Разработка и история изменений

[English](CONTRIBUTING.en.md)

Пока пакет развивается внутри `geo-registry`, его каноническая история
находится в родительском Git repository. В каталоге библиотеки нельзя создавать
вложенный `.git`: без зарегистрированного submodule родитель увидит embedded
repository, а checkout для других разработчиков потеряет содержимое пакета.

## Границы коммитов

- Изменения самой библиотеки коммитятся path-scoped набором
  `app/library/work-orchestrator/**` и содержат одну причину изменения.
- Интеграция с приложением (`app/composer.json`, `app/composer.lock` и код
  приложения) фиксируется отдельным коммитом.
- Performance checkpoint включает код benchmark и синхронизированные RU/EN
  результаты; сырые тяжёлые данные в Git не добавляются.
- Формат сообщения следует правилам родительского repository, например
  `FEAT(work-orchestrator): Добавлен process-local task pipeline`.
- Публичный API, документация и тесты одного контракта могут находиться в одном
  atomic commit. Несвязанный refactoring отделяется.

Такое разделение позволяет читать историю сейчас и позднее извлечь пакет без
ручного копирования snapshot.

## Извлечение самостоятельной истории

Когда появятся отдельный remote и правила релизов, история выделяется из
родительского repository:

```bash
git subtree split \
  --prefix=app/library/work-orchestrator \
  --branch=work-orchestrator-history
```

Команда пока не выполняется и не меняет текущую структуру. Полученную branch
можно проверить, затем отправить в новый repository. После этого отдельно
выбирается способ интеграции приложения: Composer package, зарегистрированный
Git submodule или явный subtree workflow. Вложенный незарегистрированный Git
repository не используется ни на одном этапе.

До извлечения checkpoints можно отмечать общими annotated tags с prefix
`work-orchestrator/`, но tag создаётся только для воспроизводимого состояния после
успешных проверок.

## Проверки

Из package root:

```bash
composer check
composer benchmark:worker-files -- --help
composer benchmark:record-pipeline -- --help
```

Root application lock остаётся отдельной проверкой совместимости. Коммит
библиотеки не должен незаметно изменять его зависимости.
