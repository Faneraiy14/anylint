# anylint

Кросплатформний статичний аналізатор коду з архітектурою плагінів під мови — не окремий лінтер на кожну мову, а одне ядро (пошук файлів, звіт, CLI) плюс `LanguageProvider`, що перетворює рідний AST мови в одну спільну канонічну форму. Правила пишуться раз і працюють для будь-якої мови, чий провайдер коректно віддає цю форму.

## Чому це не черговий PHP-лінтер

Це доведено, не лише задекларовано: `NyxilumProvider` підключає [NyxilumLang](https://github.com/Faneraiy14/NyxilumLang) — зовсім іншу мову з власним лексером/парсером/VM, `JavaScriptProvider`/`TypeScriptProvider` шлють файл через TypeScript compiler API, а решта чотирнадцяти мов (C, C++, C#, Java, Python, Rust, Swift, Go, Kotlin, Ruby, Dart, Zig, Objective-C, Solidity) — через tree-sitter. Структурні правила ловлять ті самі класи багів у всіх них, що й у `.php`, **без жодної зміни коду правил**.

Правила діляться на три роди — і всі мають ОДИН і той самий інтерфейс `Rule`:

- **Структурні** (`DeadCodeAfterReturnRule`, `EmptyCatchRule`) — дивляться лише на канонічне дерево (`Block`/`Return`/`TryCatch`/`CatchClause`). Жодного натяку на PHP чи NyxilumLang в їхньому коді немає.
- **Текстові** (`TodoTrackerRule`, `HardcodedSecretRule`) — сканують сирий текст файлу, ігноруючи AST. Працюють буквально на будь-якому файлі, навіть без зареєстрованого провайдера для його розширення.
- **Мовно-специфічні** (`UnusedVariableRule`) — семантика "невикористаної змінної" надто різна між мовами, щоб узагальнювати без втрати сенсу, тож це правило читає рідний `PhpParser\Node` напряму (провайдер навмисно зберігає його в кожному вузлі `FunctionDecl`). Той самий інтерфейс `Rule` — просто вужче застосування.

### Як працює `NyxilumProvider`

`nx ast файл.nx` ([AstJsonDumper.cs](https://github.com/Faneraiy14/NyxilumLang/blob/main/src/NyxilumLang/Tools/AstJsonDumper.cs) у самому NyxilumLang) виводить AST одразу в канонічній JSON-схемі `{"type","line","attributes","children"}` — тій самій, яку `PhpProvider` будує з дерева `nikic/php-parser`. `NyxilumProvider` тут лише запускає цей процес і робить `json_decode` — жодного мапування типів вузлів немає, бо узгоджений словник ("що є `Block`/`Return`/`CatchClause`") живе на стороні NyxilumLang. Потребує `nx` у `PATH` (або `NX_EXE=/шлях/до/nx`) — якщо його нема, файл, що аналізується, отримує `parse-error` Finding, а решта аналізу працює як завжди.

### Як працюють `JavaScriptProvider`/`TypeScriptProvider`

Той самий трюк, що й з `nx ast`, тільки виконавчий інструмент — власний `tools/js-ast-dump/dump.js`: TypeScript compiler API (пакет `typescript`, парсить і `.js`, і `.ts` — `allowJs` там лише про перевірку типів, парсер спільний) обходить `ts.Node`-дерево і вже сам віддає ту саму канонічну JSON-схему. `.js`/`.jsx`/`.mjs`/`.cjs` і `.ts`/`.tsx` — це два окремі класи-провайдери (один плагін = одна мова), але обидва діляться спільною логікою запуску процесу через `AbstractJsFamilyProvider`. Потребує `node` у `PATH` (або `NODE_EXE=...`) і встановлених залежностей у `tools/js-ast-dump` (`npm install` там один раз) — без цього `.js`/`.ts`-файли так само отримують `parse-error` Finding, а не мовчазний збій усього прогону.

### Як працюють tree-sitter-провайдери (C/C++/C#/Java/Python/Rust/Swift/Go/Kotlin/Ruby/Dart/Zig/Objective-C/Solidity)

Немає одного спільного офіційного compiler API для всіх цих мов (на відміну від TypeScript для JS/TS), тож тут — [tree-sitter](https://tree-sitter.github.io/) через `web-tree-sitter` (WASM-рантайм, БЕЗ нативної компіляції) і прекомпільовані `.wasm`-граматики з пакета `tree-sitter-wasms`. `tools/treesitter-ast-dump/dump.js` бере мову ПЕРШИМ аргументом і сам обирає потрібну граматику — той самий процес, що й для JS/TS, лише один движок обслуговує чотирнадцять мов одразу.

Кожна tree-sitter-граматика називає свій "блок коду" й "return" по-своєму — ця відповідність зафіксована в `LANG_CONFIG` усередині dump.js, а не вгадується налету:

- **"Блок коду"**: `compound_statement` у C/C++/Objective-C, `block` у C#/Java/Python/Rust/Go, `statements` у Swift/Kotlin (там немає окремого вузла "блок" — тіло функції й тіло `if`/`do`/`for`/`while` це один і той самий тип вузла). Ruby взагалі не має ЄДИНОГО такого вузла — тіло методу це `body_statement`, тіло `if`/`rescue` це `then`, тож `cfg.block` там масив, не рядок. Solidity's `function_body` не обгорнутий у власний `block_statement` — його прямі діти вже стейтменти функції, тож `cfg.block` там теж масив (`['block_statement', 'function_body']`), інакше мертвий код одразу на верхньому рівні функції лишався б непоміченим.
- **`return`**: у Rust і Zig це `return_expression`, обгорнутий у `expression_statement` (return — вираз, не стейтмент) — без окремого розгортання (`unwrapExpressionStatement()` у dump.js) він був би не прямою дитиною блоку, а онуком, і `dead-code-after-return` (яка дивиться лише на прямих дітей `Block`) ніколи б його не побачила. У Swift `return`/`break`/`continue`/`throw` і в Kotlin `return`/`break`/`continue` — це ОДИН тип вузла (`control_transfer_statement`/`jump_expression`); розрізняються лише за текстом першого токена, тож `isReturn()` у dump.js звіряє саме його, а не сам тип вузла.
- **`catch`/`except`/`rescue`**: Python називає це `except`, Ruby — `rescue`, семантика та сама. Rust, Go і Zig не мають традиційного механізму винятків (Result/panic, defer/recover, і `catch`-як-оператор відповідно) — там `tryStmt`/`catchClause` просто `null`, і жодних TryCatch/CatchClause-вузлів не з'являється. Dart і Solidity мають цікаву різницю: у Dart тіло `catch` — НЕ дитина `catch_clause`, а наступний СУСІД під тим самим `try_statement`; у Ruby тіло `begin` (try-аналог) взагалі не обгорнуте в окремий блок — стейтменти try-частини ПРЯМІ діти `begin`, перемішані з `rescue`/`ensure` як сусіди. Обидва кейси розбирає окрема `mapTryCatchChildren()` у dump.js, а не генерична логіка.

Потребує `node` у `PATH` і `npm install` у `tools/treesitter-ast-dump` — так само, як і для JS/TS-дампера.

Не всі мови з `tree-sitter-wasms` увійшли: Lua (у бандлованій `.wasm`-збірці граматика ламається на БУДЬ-якому багаторядковому тілі функції — очевидний баг конкретно цього білда) і Scala (парсить `return <значення>` як синтаксичну помилку, хоча `return` без значення працює) дали хибні `parse-error` на звичайному робочому коді — краще чесно не підтримувати мову, ніж видавати провайдер, який заважає більше, ніж допомагає. Bash теж відкладено: `return` там не окремий тип вузла (це просто виклик команди з іменем "return", треба звіряти текст), а тіло `if`/`while` взагалі не обгортається в окремий блок-вузол — підтримка вийшла б суттєво слабшою за решту мов.

## Встановлення й запуск

```bash
composer install
php bin/anylint шлях/до/коду
```

Для аналізу `.js`/`.ts`- і всіх tree-sitter-мов (C/C++/C#/Java/Python/Rust/Swift/Go/Kotlin/Ruby/Dart/Zig/Objective-C/Solidity) додатково потрібно один раз:

```bash
cd tools/js-ast-dump && npm install && cd -
cd tools/treesitter-ast-dump && npm install && cd -
```

```bash
php bin/anylint src --json      # машинний формат, exit 1 якщо є помилки
php bin/anylint src --no-todo   # без todo-tracker
```

## Що вже ловить

| Правило | Тип | Що знаходить |
|---|---|---|
| `dead-code-after-return` | структурне | код одразу після `return`, який ніколи не виконається |
| `deep-nesting` | структурне | `if`/`for`/`while`/`try` вкладені одне в одне глибше 4 рівнів — важко читати й тестувати |
| `empty-catch` | структурне | `catch` без жодної дії всередині — помилка проковтується мовчки |
| `empty-function` | структурне (FunctionDecl: PHP, JS/TS) | тіло функції порожнє — забута реалізація чи заглушка |
| `long-function` | структурне (FunctionDecl: PHP, JS/TS) | понад 30 стейтментів на верхньому рівні функції — варто розбити |
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
  Providers/AbstractJsFamilyProvider.php — спільний запуск tools/js-ast-dump/dump.js -> канонічне дерево
  Providers/JavaScriptProvider.php, TypeScriptProvider.php — розширення файлів для AbstractJsFamilyProvider
  Providers/AbstractTreeSitterProvider.php — спільний запуск tools/treesitter-ast-dump/dump.js -> канонічне дерево
  Providers/CProvider.php, CppProvider.php, CSharpProvider.php, JavaProvider.php,
    PythonProvider.php, RustProvider.php, SwiftProvider.php, GoProvider.php,
    KotlinProvider.php, RubyProvider.php, DartProvider.php, ZigProvider.php,
    ObjectiveCProvider.php, SolidityProvider.php — розширення файлів + мова tree-sitter для AbstractTreeSitterProvider
tools/js-ast-dump/dump.js          — TypeScript compiler API -> та сама канонічна JSON-схема, що й "nx ast"
tools/treesitter-ast-dump/dump.js  — tree-sitter (14 мов, LANG_CONFIG) -> та сама канонічна JSON-схема
  Rules/*                — самі правила
  Analyzer.php            — обхід файлів, вибір провайдера, запуск правил
bin/anylint                — CLI
```

Додати нову мову = написати клас `LanguageProvider`, зареєструвати `->withProvider(new ...)` в `bin/anylint` — жодних змін у ядрі чи в існуючих структурних/текстових правилах.

## Стійкість до нових версій PHP

CI ганяє тести на матриці версій PHP (8.1–8.4) плюс окремо на nightly-збірці — щоб несумісність із новим релізом PHP виявилась за кілька хвилин у Actions, а не через рік боляче вручну. Єдина реальна залежність — `nikic/php-parser`, активно підтримуваний пакет, що сам оновлюється під нові версії PHP; `composer.lock` закомічено для відтворюваних збірок.

## Ліцензія

MIT, див. [LICENSE](LICENSE).
