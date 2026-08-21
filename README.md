# anylint

Кросплатформний статичний аналізатор коду з архітектурою плагінів під мови — не окремий лінтер на кожну мову, а одне ядро (пошук файлів, звіт, CLI) плюс `LanguageProvider`, що перетворює рідний AST мови в одну спільну канонічну форму. Правила пишуться раз і працюють для будь-якої мови, чий провайдер коректно віддає цю форму.

## Чому це не черговий PHP-лінтер

Це доведено, не лише задекларовано: `NyxilumProvider` підключає [NyxilumLang](https://github.com/Faneraiy14/NyxilumLang) — зовсім іншу мову з власним лексером/парсером/VM — і структурні правила ловлять ті самі класи багів у `.nx`, що й у `.php`, **без жодної зміни коду правил**.

Правила діляться на три роди — і всі мають ОДИН і той самий інтерфейс `Rule`:

- **Структурні** (`DeadCodeAfterReturnRule`, `EmptyCatchRule`) — дивляться лише на канонічне дерево (`Block`/`Return`/`TryCatch`/`CatchClause`). Жодного натяку на PHP чи NyxilumLang в їхньому коді немає.
- **Текстові** (`TodoTrackerRule`, `HardcodedSecretRule`) — сканують сирий текст файлу, ігноруючи AST. Працюють буквально на будь-якому файлі, навіть без зареєстрованого провайдера для його розширення.
- **Мовно-специфічні** (`UnusedVariableRule`) — семантика "невикористаної змінної" надто різна між мовами, щоб узагальнювати без втрати сенсу, тож це правило читає рідний `PhpParser\Node` напряму (провайдер навмисно зберігає його в кожному вузлі `FunctionDecl`). Той самий інтерфейс `Rule` — просто вужче застосування.

### Як працює `NyxilumProvider`

`nx ast файл.nx` ([AstJsonDumper.cs](https://github.com/Faneraiy14/NyxilumLang/blob/main/src/NyxilumLang/Tools/AstJsonDumper.cs) у самому NyxilumLang) виводить AST одразу в канонічній JSON-схемі `{"type","line","attributes","children"}` — тій самій, яку `PhpProvider` будує з дерева `nikic/php-parser`. `NyxilumProvider` тут лише запускає цей процес і робить `json_decode` — жодного мапування типів вузлів немає, бо узгоджений словник ("що є `Block`/`Return`/`CatchClause`") живе на стороні NyxilumLang. Потребує `nx` у `PATH` (або `NX_EXE=/шлях/до/nx`) — якщо його нема, `.nx`-файли просто пропускаються провайдером, решта аналізу працює як завжди.

## Встановлення й запуск

```bash
composer install
php bin/anylint шлях/до/коду
```

```bash
php bin/anylint src --json      # машинний формат, exit 1 якщо є помилки
php bin/anylint src --no-todo   # без todo-tracker
```

## Що вже ловить

| Правило | Тип | Що знаходить |
|---|---|---|
| `dead-code-after-return` | структурне | код одразу після `return`, який ніколи не виконається |
| `empty-catch` | структурне | `catch` без жодної дії всередині — помилка проковтується мовчки |
| `unused-variable` | PHP-специфічне | змінна присвоюється й ніде більше не з'являється |
| `hardcoded-secret` | текстове | GitHub/AWS-токени, приватні ключі, `password = "..."` прямо в коді |
| `todo-tracker` | текстове | `// TODO` / `# FIXME` в коментарях (не в рядках чи іменах) |

## Архітектура

```
src/
  Ast/Node.php          — канонічний вузол (type, line, attributes, children, native)
  LanguageProvider.php  — інтерфейс плагіна мови: supports($file), parse($file): Node
  Rule.php               — інтерфейс правила: check($root, $source, $file): Finding[]
  Providers/PhpProvider.php     — nikic/php-parser -> канонічне дерево
  Providers/NyxilumProvider.php — `nx ast` (JSON) -> канонічне дерево
  Rules/*                — самі правила
  Analyzer.php            — обхід файлів, вибір провайдера, запуск правил
bin/anylint                — CLI
```

Додати нову мову = написати клас `LanguageProvider`, зареєструвати `->withProvider(new ...)` в `bin/anylint` — жодних змін у ядрі чи в існуючих структурних/текстових правилах.

## Стійкість до нових версій PHP

CI ганяє тести на матриці версій PHP (8.1–8.4) плюс окремо на nightly-збірці — щоб несумісність із новим релізом PHP виявилась за кілька хвилин у Actions, а не через рік боляче вручну. Єдина реальна залежність — `nikic/php-parser`, активно підтримуваний пакет, що сам оновлюється під нові версії PHP; `composer.lock` закомічено для відтворюваних збірок.

## Ліцензія

MIT, див. [LICENSE](LICENSE).
