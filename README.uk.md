# anylint

*[English](README.md)*

Кросплатформний статичний аналізатор коду з архітектурою плагінів під мови — не окремий лінтер на кожну мову, а одне ядро (пошук файлів, звіт, CLI) плюс `LanguageProvider`, що перетворює рідний AST мови в одну спільну канонічну форму. Правила пишуться раз і працюють для будь-якої мови, чий провайдер коректно віддає цю форму.

## Чому це не черговий PHP-лінтер

Це доведено, не лише задекларовано: `NyxilumProvider` підключає [NyxilumLang](https://github.com/Faneraiy14/NyxilumLang) — зовсім іншу мову з власним лексером/парсером/VM, `JavaScriptProvider`/`TypeScriptProvider` шлють файл через TypeScript compiler API, а решта п'ятнадцяти мов (C, C++, C#, Java, Python, Rust, Swift, Go, Kotlin, Lua, Ruby, Dart, Zig, Objective-C, Solidity) — через tree-sitter. Структурні правила ловлять ті самі класи багів у всіх них, що й у `.php`, **без жодної зміни коду правил**.

Правила діляться на три роди — і всі мають ОДИН і той самий інтерфейс `Rule`:

- **Структурні** (`DeadCodeAfterReturnRule`, `EmptyCatchRule`) — дивляться лише на канонічне дерево (`Block`/`Return`/`TryCatch`/`CatchClause`). Жодного натяку на PHP чи NyxilumLang в їхньому коді немає.
- **Текстові** (`TodoTrackerRule`, `HardcodedSecretRule`) — сканують сирий текст файлу, ігноруючи AST. Працюють буквально на будь-якому файлі, навіть без зареєстрованого провайдера для його розширення.
- **Мовно-специфічні** (`UnusedVariableRule`) — семантика "невикористаної змінної" надто різна між мовами, щоб узагальнювати без втрати сенсу, тож це правило читає рідний `PhpParser\Node` напряму (провайдер навмисно зберігає його в кожному вузлі `FunctionDecl`). Той самий інтерфейс `Rule` — просто вужче застосування.

### Як працює `NyxilumProvider`

`nx ast файл.nx` ([AstJsonDumper.cs](https://github.com/Faneraiy14/NyxilumLang/blob/main/src/NyxilumLang/Tools/AstJsonDumper.cs) у самому NyxilumLang) виводить AST одразу в канонічній JSON-схемі `{"type","line","attributes","children"}` — тій самій, яку `PhpProvider` будує з дерева `nikic/php-parser`. `NyxilumProvider` тут лише запускає цей процес і робить `json_decode` — жодного мапування типів вузлів немає, бо узгоджений словник ("що є `Block`/`Return`/`CatchClause`") живе на стороні NyxilumLang. Потребує `nx` у `PATH` (або `NX_EXE=/шлях/до/nx`) — якщо його нема, файл, що аналізується, отримує `parse-error` Finding, а решта аналізу працює як завжди.

### Як працюють `JavaScriptProvider`/`TypeScriptProvider`

Той самий трюк, що й з `nx ast`, тільки виконавчий інструмент — власний `tools/js-ast-dump/dump.js`: TypeScript compiler API (пакет `typescript`, парсить і `.js`, і `.ts` — `allowJs` там лише про перевірку типів, парсер спільний) обходить `ts.Node`-дерево і вже сам віддає ту саму канонічну JSON-схему. `.js`/`.jsx`/`.mjs`/`.cjs` і `.ts`/`.tsx` — це два окремі класи-провайдери (один плагін = одна мова), але обидва діляться спільною логікою запуску процесу через `AbstractJsFamilyProvider`. Потребує `node` у `PATH` (або `NODE_EXE=...`) і встановлених залежностей у `tools/js-ast-dump` (`npm install` там один раз) — без цього `.js`/`.ts`-файли так само отримують `parse-error` Finding, а не мовчазний збій усього прогону.

### Як працюють tree-sitter-провайдери (C/C++/C#/Java/Python/Rust/Swift/Go/Kotlin/Lua/Ruby/Dart/Zig/Objective-C/Solidity)

Немає одного спільного офіційного compiler API для всіх цих мов (на відміну від TypeScript для JS/TS), тож тут — [tree-sitter](https://tree-sitter.github.io/) через `web-tree-sitter` (WASM-рантайм, БЕЗ нативної компіляції) і прекомпільовані `.wasm`-граматики з пакета `tree-sitter-wasms`. `tools/treesitter-ast-dump/dump.js` бере мову ПЕРШИМ аргументом і сам обирає потрібну граматику — той самий процес, що й для JS/TS, лише один движок обслуговує п'ятнадцять мов одразу.

Кожна tree-sitter-граматика називає свій "блок коду" й "return" по-своєму — ця відповідність зафіксована в `LANG_CONFIG` усередині dump.js, а не вгадується налету:

- **"Блок коду"**: `compound_statement` у C/C++/Objective-C, `block` у C#/Java/Python/Rust/Go/Lua, `statements` у Swift/Kotlin (там немає окремого вузла "блок" — тіло функції й тіло `if`/`do`/`for`/`while` це один і той самий тип вузла). Ruby взагалі не має ЄДИНОГО такого вузла — тіло методу це `body_statement`, тіло `if`/`rescue` це `then`, тож `cfg.block` там масив, не рядок. Solidity's `function_body` не обгорнутий у власний `block_statement` — його прямі діти вже стейтменти функції, тож `cfg.block` там теж масив (`['block_statement', 'function_body']`), інакше мертвий код одразу на верхньому рівні функції лишався б непоміченим.
- **`return`**: у Rust і Zig це `return_expression`, обгорнутий у `expression_statement` (return — вираз, не стейтмент) — без окремого розгортання (`unwrapExpressionStatement()` у dump.js) він був би не прямою дитиною блоку, а онуком, і `dead-code-after-return` (яка дивиться лише на прямих дітей `Block`) ніколи б його не побачила. У Swift `return`/`break`/`continue`/`throw` і в Kotlin `return`/`break`/`continue` — це ОДИН тип вузла (`control_transfer_statement`/`jump_expression`); розрізняються лише за текстом першого токена, тож `isReturn()` у dump.js звіряє саме його, а не сам тип вузла. Lua йде ще далі: `return` за самою ГРАМАТИКОЮ зобов'язаний бути останнім стейтментом свого блоку — код одразу після нього в тому самому блоці це справжня синтаксична помилка, не питання стилю, тож `dead-code-after-return` може побачити його лише через ідіому `do return end` (return усередині вкладеного `do...end`-скоупу, яка коректно НЕ рахується мертвим кодом для зовнішнього блоку).
- **`catch`/`except`/`rescue`**: Python називає це `except`, Ruby — `rescue`, семантика та сама. Порожній `except` у Python — синтаксична помилка, тож `except: pass` і є Python-формою `catch (e) {}`: dump.js викидає `pass` лише з тіла `except` (`catchNoopStmt`), і `empty-catch` бачить його порожнім, а `def hook(self): pass` (ідіома абстрактних методів і хуків) лишається звичайним стейтментом. Коментар поруч із цим `pass` і далі позначає `except` як свідомий, зокрема той, що tree-sitter кладе над тілом, поза блоком. Rust, Go, Zig і Lua не мають традиційного механізму винятків (Result/panic, defer/recover, `catch`-як-оператор, і `pcall`/`xpcall` — звичайні виклики функцій, не синтаксис — відповідно) — там `tryStmt`/`catchClause` просто `null`, і жодних TryCatch/CatchClause-вузлів не з'являється. Dart і Solidity мають цікаву різницю: у Dart тіло `catch` — НЕ дитина `catch_clause`, а наступний СУСІД під тим самим `try_statement`; у Ruby тіло `begin` (try-аналог) взагалі не обгорнуте в окремий блок — стейтменти try-частини ПРЯМІ діти `begin`, перемішані з `rescue`/`ensure` як сусіди. Обидва кейси розбирає окрема `mapTryCatchChildren()` у dump.js, а не генерична логіка.
- **Порожні тіла без вузла-блока взагалі**: мови з фігурними дужками завжди лишають вузол `compound_statement`/`block`, навіть коли `{}` порожні, тож `empty-block`/`empty-function` просто перевіряють дітей цього вузла. Lua не має фігурних дужок ВЗАГАЛІ (`if`/`while`/`for`/`repeat`/`function`...`end`), і її граматика ПОВНІСТЮ пропускає вузол-блок, коли тіло порожнє — `if x then end` дає нуль дітей, а не `block` із нулем дітей. Перевірено безпосередньо на дереві розбору перед тим, як на це покладатись, не здогадка. Прапорець `funcBodyIsImplicit` (спільний з Ruby, у якої та сама відсутність дужок) і новіший `controlBodyIsImplicit` синтезують порожній `Block` саме в цій ситуації, тож обидва правила на порожність бачать ту саму форму, що й у будь-якій іншій мові.
- **`for` не завжди один тип вузла**: більшість мов переюзують `for_statement`/`for_expression` для будь-якого виду `for`-циклу (включно з Python, де немає C-стильного `for`, тож `foreach: 'for_statement'` там же). Граматика Lua натомість розділяє числовий `for i = 1, 10 do` і generic `for k, v in pairs(t) do` на ДВА різних типи вузлів (`for_numeric_statement`/`for_generic_statement`), жоден з яких не збігається з дефолтним — обробляється через конфіг-масив `forStmt`, а не хардкодженням другого спецвипадку у спільний матчер. Так само `do_statement` в усіх решта мов тут означає "do-while цикл", а в Lua це просто лексичний скоуп-блок (жодної циклової семантики, типово для раннього виходу `do return end`) — змапити його на `Do` означало б хибно рахувати звичайний скоуп циклом, тож `doStmt` дозволяє Lua перенаправити канонічний тип `Do` на СПРАВЖНІЙ цикл із постумовою (`repeat...until`), а голий скоуп-блок лишається нетипізованим, не-керуючим вузлом.

Потребує `node` у `PATH` і `npm install` у `tools/treesitter-ast-dump` — так само, як і для JS/TS-дампера.

Не всі мови з `tree-sitter-wasms` увійшли: Scala (парсить `return <значення>` як синтаксичну помилку, хоча `return` без значення працює — відтворено заново на актуально запінченій версії граматики перед написанням цього розділу) дала хибні `parse-error` на звичайному робочому коді — краще чесно не підтримувати мову, ніж видавати провайдер, який заважає більше, ніж допомагає. Bash теж відкладено: `return` там не окремий тип вузла (це просто виклик команди з іменем "return", треба звіряти текст), а тіло `if`/`while` взагалі не обгортається в окремий блок-вузол — підтримка вийшла б суттєво слабшою за решту мов. (Lua раніше теж була тут через баг граматики, що ламався на будь-якому багаторядковому тілі функції — перевірено заново на актуально запінченій версії `tree-sitter-wasms` під час написання цього розділу, і баг більше не відтворюється, тож Lua тепер підтримується.)

## Встановлення й запуск

```bash
composer install
php bin/anylint шлях/до/коду
```

Для аналізу `.js`/`.ts`- і всіх tree-sitter-мов (C/C++/C#/Java/Python/Rust/Swift/Go/Kotlin/Lua/Ruby/Dart/Zig/Objective-C/Solidity) додатково потрібно один раз:

```bash
cd tools/js-ast-dump && npm install && cd -
cd tools/treesitter-ast-dump && npm install && cd -
```

```bash
php bin/anylint src --json      # машинний формат, exit 1 якщо є помилки
php bin/anylint src --checkstyle # Checkstyle XML — PhpStorm та інші JetBrains IDE імпортують
                                 # це нативно через File Watcher, без окремого плагіна
php bin/anylint src --no-todo   # без todo-tracker
php bin/anylint src --sarif     # SARIF 2.1.0 — GitHub Code Scanning, GitLab, Azure DevOps
php bin/anylint src --github    # анотації GitHub Actions (видно в диффі PR)
php bin/anylint src --fix       # застосувати автовиправлення, далі показати решту
php bin/anylint src --no-cache  # без кешу результатів
php bin/anylint src --jobs auto # паралельно (процес на кожне ядро)
```

Коди виходу: `0` знахідок нема, `1` є знахідки, `2` помилка запуску чи конфігу.

## GitHub Action

```yaml
name: anylint
on: [push, pull_request]
jobs:
  anylint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
      - uses: Faneraiy14/anylint@main
        with:
          path: src          # типово: увесь репозиторій
          args: --no-todo    # додаткові аргументи CLI, anylint.json підхоплюється й так
```

Знахідки з'являються анотаціями прямо в диффі PR — це працює в будь-якому репозиторії, зокрема приватному, без додаткових прав. На сторінці запуску з'являється зведення, а повний HTML-звіт додається артефактом `anylint-report`. Крок падає, якщо є знахідки (`fail-on-findings: 'false'` — лише показати).

| Вхід | Типово | |
|---|---|---|
| `path` | `.` | файл чи тека для аналізу |
| `args` | | додаткові аргументи перевірки (`--baseline ...`, `--jobs auto`), діляться за пробілами |
| `fail-on-findings` | `true` | падати, якщо є знахідки; помилка конфігу падає завжди |
| `sarif` | `false` | ще й вивантажити в GitHub Code Scanning — потрібні `permissions: security-events: write` (і GitHub Advanced Security для приватних репозиторіїв) |
| `report` | `true` | HTML-звіт артефактом + зведення запуску |
| `php-version` | `8.3` | PHP, яким запускається anylint |
| `node` | `true` | встановити Node.js і AST-дампери; `false` для суто PHP-проєкту економить близько хвилини |

Виходи: `findings`, `exit-code`, `sarif-file`, `report-file`. Поза action'ом `php bin/anylint src --github` друкує ті самі анотації в будь-якому workflow.

## Звіт про проєкт

```bash
php bin/anylint report src                                  # зведення в терміналі
php bin/anylint report src --format html --output report.html
php bin/anylint report src --format json                    # ті самі дані для машин
```

Це не список знахідок, а стан коду: файли й рядки коду за мовами, знахідки за правилами й серйозністю, найскладніші функції, «гарячі» файли (помилка = 3, попередження = 1; `info`/TODO не рахуються) і частка дубльованого коду. HTML-звіт — один самодостатній файл (без скриптів, шрифтів і CDN) зі світлою й темною темою: відкривається офлайн, прикріплюється як артефакт CI. Baseline свідомо **не** застосовується: він ховає прийняті знахідки від CI-перевірки, а звіт показує весь борг. Коментарі-приглушення діють. Звіт завжди виходить з `0` — це не перевірка.

### Складність

Обидві метрики рахуються з канонічного дерева, тож означають те саме в кожній мові з функціями:

- **цикломатична** = 1 + кількість розгалужень (`if`, цикли, `catch`);
- **когнітивна** = кожне розгалуження коштує 1 і ще 1 за кожен рівень вкладеності, у якому стоїть (ідея Cognitive Complexity від Sonar). Ланцюжок `else if` — продовження, а не вкладеність.

Чесне обмеження: `&&`/`||` і гілки `switch` не рахуються — PHP-дерево будується лише до рівня стейтментів, а рахувати їх лише для частини мов зробило б числа непорівнянними. Тому значення трохи нижчі за Sonar-івські для того самого коду.

## Швидкість

- **Один довгоживучий процес Node.js на dumper**, а не на кожен файл. Запуск `node` і завантаження компілятора TypeScript коштували ~0,6 с на кожен JS/TS-файл; тепер dumper-и працюють з `--serve` (рядок JSON на запит у stdin, рядок JSON на відповідь у stdout), а `NodeWorker` тримає процес на весь прогін. Виміряно на реальному проєкті (162 JS + 18 Java + 44 PHP): **125 с → 7,2 с**, знахідки ідентичні до байта. Виняток — Lua: її граматика tree-sitter правильно розбирає лише *перший* файл у процесі (кожен наступний — хибна синтаксична помилка), тож Lua-файли й далі отримують одноразовий `node` кожен.
- **Кеш результатів**: незмінений файл не розбирається вдруге — повторний прогін того ж проєкту триває **0,35 с**. Кеш лежить у системній тимчасовій теці (окремий файл на кожен корінь аналізу, як у PHPStan), тож у репозиторії нічого не з'являється. Він прив'язаний до коду самого anylint, набору правил, опцій і severity, версій зовнішніх інструментів (`nx`) — зміна будь-чого скидає його повністю. Результати з `parse-error` не кешуються (це може означати «node не встановлено», а не «файл зламаний»). `--no-cache` вимикає кеш.
- **`--jobs <N|auto>`** аналізує в N паралельних процесах (`pcntl_fork`, динамічна черга, знахідки в тому ж порядку, що й послідовно). **Типово вимкнено**: на 2-ядерному ноутбуці, де це писалось, кожен додатковий процес ще раз платить за старт Node, і прогін ставав повільнішим (7,7 с → 8,3 с з 4 процесами) — вільних ядер просто не було. Варто пробувати на машині з вільними ядрами. Без розширення `pcntl` (напр. Windows) аналіз мовчки йде послідовно.
- Файли аналізуються у відсортованому порядку шляхів, тож вивід стабільний між запусками.

## Конфігурація

anylint шукає `anylint.json` (або `.anylint.json`) від теки аналізованого шляху вгору до кореня файлової системи, тож запуск з будь-якої підтеки бере конфіг проєкту. `--config <файл>` задає його явно, `--no-config` вимикає пошук.

```json
{
  "rules": {
    "todo-tracker": "off",
    "empty-catch": "error",
    "deep-nesting": { "severity": "warning", "maxDepth": 3 },
    "long-function": { "maxStatements": 50 },
    "parse-error": "warning"
  },
  "exclude": ["generated", "src/**/*.gen.php", "legacy/old/"],
  "baseline": "anylint-baseline.json"
}
```

- Значення правила - severity (`off`, `info`, `warning`, `error`) або об'єкт з необов'язковим `severity` і опціями цього правила. Зараз опції мають `deep-nesting` (`maxDepth`, типово 4) і `long-function` (`maxStatements`, типово 30).
- `parse-error` і `unreadable-file` (знахідки самого аналізатора) теж можна перевизначити - напр., коли на машині немає `nx`/`node`.
- `exclude` - glob-шаблони на кшталт `.gitignore`, відносно файлу конфігу: шаблон без `/` збігається з назвою файлу чи теки на будь-якій глибині; шаблон зі `/` - зі шляхом від теки конфігу (і всім під ним); `*`, `**`, `?` - як зазвичай. Вбудований список пропусків (`vendor`, `node_modules`, `dist`, ...) діє й далі.
- Невідома назва правила, невідома опція чи неприйнятне значення - помилка (код виходу 2), а не мовчазне ігнорування: інакше друкарська помилка виглядала б як налаштування, що спрацювало.

## Приглушення знахідок

Ті самі коментарі-директиви працюють для всіх правил і всіх мов, бо читаються з сирого тексту файлу, а не з AST:

```php
// anylint-disable-next-line empty-catch -- свідомо ковтаємо, див. #42
try { flush(); } catch (\Throwable $e) {}

$legacy = deep_call(); // anylint-disable-line deep-nesting, long-function
```

```python
# anylint-disable-file todo-tracker
```

- `anylint-disable-next-line`, `anylint-disable-line`, `anylint-disable-file`, далі назви правил (через кому чи пробіл). Без назв - усі правила. Текст після ` -- ` - пояснення для людей.
- Директива має стояти одразу після маркера коментаря (`//`, `#`, `/*`, `*`, `--`, `;`, `<!--`). Рядковий літерал чи речення, яке просто згадує назву директиви, нічого не робить.
- Старий `anylint:ignore` працює й далі та приглушує всі правила на своєму рядку.

## Baseline: впровадження в наявний проєкт

```bash
php bin/anylint src --generate-baseline   # прийняти всі поточні знахідки, exit 0
php bin/anylint src                       # далі показуються лише НОВІ знахідки
```

Файл baseline — `anylint-baseline.json` поруч з `anylint.json` (або в поточній теці, якщо конфігу нема); інший шлях — `"baseline": "path.json"` у конфігу чи `--baseline <файл>`. Його варто комітити: шляхи всередині відносні до самого файлу.

Знахідка впізнається за файлом, правилом і повідомленням з лічильником, а не за номером рядка (так само, як у PHPStan). Нові рядки над старою знахідкою не роблять її знову «новою», а друга така сама знахідка в тому ж файлі все одно буде показана. Шлях `baseline` у конфігу, якого ще не створено, — не помилка; неіснуючий файл у `--baseline <файл>` — помилка.

## Автовиправлення

`--fix` застосовує всі виправлення, які правила додають до знахідок (зараз це `promotable-return-type`: перетворює `@return` з docblock на нативний тип повернення), потім аналізує ще раз і показує, що лишилось. Кількість виправлень іде в stderr, тож `--fix --json`/`--fix --sarif` лишаються машинними форматами. Правки застосовуються з кінця файлу; якщо дві правки перетинаються, застосовується лише одна, а наступний запуск `--fix` підхопить іншу.

## Що вже ловить

| Правило | Тип | Що знаходить |
|---|---|---|
| `dead-code-after-return` | структурне | код одразу після `return`, який ніколи не виконається |
| `deep-nesting` | структурне | `if`/`for`/`while`/`try` вкладені одне в одне глибше 4 рівнів — важко читати й тестувати |
| `empty-block` | структурне | порожнє тіло `if`/`for`/`foreach`/`while`/`do` — забута логіка чи заглушка |
| `empty-catch` | структурне | `catch` без жодної дії всередині — помилка проковтується мовчки |
| `empty-function` | структурне (FunctionDecl: усі мови, крім Objective-C) | тіло функції порожнє — забута реалізація чи заглушка |
| `long-function` | структурне (та сама межа покриття, що й `empty-function`) | понад 30 стейтментів на верхньому рівні функції — варто розбити |
| `unused-variable` | PHP-специфічне | змінна присвоюється й ніде більше не з'являється |
| `promotable-return-type` | PHP-специфічне, з автофіксом | `@return TYPE` у докблоці методу можна зробити нативним типом |
| `hardcoded-secret` | текстове | GitHub/AWS-токени, приватні ключі, `password = "..."` прямо в коді |
| `todo-tracker` | текстове | `// TODO` / `# FIXME` / `-- TODO` в коментарях (не в рядках чи іменах) |
| `complex-function` | структурне (те саме покриття, що й `empty-function`) | когнітивна складність понад 15 (опція `maxCognitive`) — див. [Складність](#складність) |
| `duplicate-code` | на весь проєкт (усі файли з мовою) | фрагмент від 8 рядків (опція `minLines`), що повторюється в іншому місці чи файлі. Порівнюється нормалізований текст — пробіли, порожні рядки, рядки з самої пунктуації та імпорти ігноруються, тож перейменовані змінні не ловляться |
| `windows-script-encoding` | текстове, лише `.ps1`/`.bat`/`.cmd` | кирилиця (чи інше не-ASCII) без UTF-8 BOM у `.ps1` — легасі Windows PowerShell 5.1 читає неправильно; будь-яке не-ASCII в `.bat`/`.cmd` — кодування `cmd.exe` ненадійне незалежно від BOM |

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
    KotlinProvider.php, LuaProvider.php, RubyProvider.php, DartProvider.php, ZigProvider.php,
    ObjectiveCProvider.php, SolidityProvider.php — розширення файлів + мова tree-sitter для AbstractTreeSitterProvider
tools/js-ast-dump/dump.js          — TypeScript compiler API -> та сама канонічна JSON-схема, що й "nx ast"
tools/treesitter-ast-dump/dump.js  — tree-sitter (15 мов, LANG_CONFIG) -> та сама канонічна JSON-схема
  Rules/*                — самі правила
  ProjectRule.php        — правило на весь проєкт: collect() на файл (кешується), finish() над усіма фактами
  Metrics/Complexity.php — цикломатична/когнітивна складність і рядки коду з канонічного дерева
  FileResult.php, AnalysisResult.php — знахідки + метрики файлу (це несуть кеш і --jobs)
  Report/*               — `anylint report`: ProjectReport (модель = JSON), TextReport, HtmlReport
  Ast/NodeDecoder.php    — канонічний JSON від dump.js / `nx ast` -> Node, з перевіркою форми
  Formatters/CheckstyleFormatter.php — Finding[] -> Checkstyle XML (для --checkstyle)
  Formatters/SarifFormatter.php — Finding[] -> SARIF 2.1.0 (для --sarif)
  Formatters/GithubFormatter.php — Finding[] -> workflow-команди GitHub Actions (для --github)
  Baseline.php, Fixer.php — --generate-baseline/--baseline, --fix
  ResultCache.php, ParallelRunner.php — кеш результатів, --jobs
  Providers/NodeWorker.php — один довгоживучий `node dump.js --serve` на dumper
  Config.php, PathMatcher.php — завантаження/перевірка anylint.json, glob-и exclude
  Suppressions.php        — директиви anylint-disable-*, застосовуються централізовано для всіх правил
  Analyzer.php            — обхід файлів, вибір провайдера, запуск правил
bin/anylint                — CLI
```

Кожен провайдер віддає ланцюжок `if / else if / else` в одній канонічній формі — `If[Block(then), If(наступна) | Block(else)]`, — хоч би як його подавав парсер (обгортки `else_clause` у C/Rust/Zig, пласкі сусіди `elif` у Python/Lua, плаский `elseif` у PHP...). `tests/fixtures/else-if-chain/` містить той самий ланцюжок кожною мовою, а `MetricsTest` перевіряє, що кожна дає цикломатичну 4 і когнітивну 3.

Додати нову мову = написати клас `LanguageProvider`, зареєструвати `->withProvider(new ...)` в `bin/anylint` — жодних змін у ядрі чи в існуючих структурних/текстових правилах.

## Стійкість до нових версій PHP

CI ганяє тести на матриці версій PHP (8.1–8.4) плюс окремо на nightly-збірці — щоб несумісність із новим релізом PHP виявилась за кілька хвилин у Actions, а не через рік боляче вручну. Єдина реальна залежність — `nikic/php-parser`, активно підтримуваний пакет, що сам оновлюється під нові версії PHP; `composer.lock` закомічено для відтворюваних збірок.

## Ліцензія

MIT, див. [LICENSE](LICENSE).
