<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AnyLint\Analyzer;
use AnyLint\Providers\CProvider;
use AnyLint\Providers\CppProvider;
use AnyLint\Providers\CSharpProvider;
use AnyLint\Providers\DartProvider;
use AnyLint\Providers\GoProvider;
use AnyLint\Providers\JavaProvider;
use AnyLint\Providers\JavaScriptProvider;
use AnyLint\Providers\KotlinProvider;
use AnyLint\Providers\NyxilumProvider;
use AnyLint\Providers\ObjectiveCProvider;
use AnyLint\Providers\PhpProvider;
use AnyLint\Providers\PythonProvider;
use AnyLint\Providers\RubyProvider;
use AnyLint\Providers\RustProvider;
use AnyLint\Providers\SolidityProvider;
use AnyLint\Providers\SwiftProvider;
use AnyLint\Providers\TypeScriptProvider;
use AnyLint\Providers\ZigProvider;
use AnyLint\Rules\DeadCodeAfterReturnRule;
use AnyLint\Rules\DeepNestingRule;
use AnyLint\Rules\EmptyBlockRule;
use AnyLint\Rules\EmptyCatchRule;
use AnyLint\Rules\EmptyFunctionRule;
use AnyLint\Rules\HardcodedSecretRule;
use AnyLint\Rules\LongFunctionRule;
use AnyLint\Rules\TodoTrackerRule;
use AnyLint\Rules\UnusedVariableRule;

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        echo "  ✅ {$label}\n";
        $passed++;
    } else {
        echo "  ❌ {$label}\n";
        $failures++;
    }
}

function tempPhpFile(string $contents): string
{
    $dir = sys_get_temp_dir() . '/anylint_test_' . uniqid('', true);
    mkdir($dir);
    $path = $dir . '/test.php';
    file_put_contents($path, "<?php\n" . $contents);
    return $path;
}

function rrmdir(string $dir): void
{
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function newAnalyzer(): Analyzer
{
    return (new Analyzer())
        ->withProvider(new PhpProvider())
        ->withRule(new DeadCodeAfterReturnRule())
        ->withRule(new DeepNestingRule())
        ->withRule(new EmptyBlockRule())
        ->withRule(new EmptyCatchRule())
        ->withRule(new EmptyFunctionRule())
        ->withRule(new HardcodedSecretRule())
        ->withRule(new LongFunctionRule())
        ->withRule(new UnusedVariableRule())
        ->withRule(new TodoTrackerRule());
}

// --- Тест 1: dead-code-after-return ---
echo "1. DeadCodeAfterReturnRule\n";
$f = tempPhpFile('function f() { return 1; echo "мертвий код"; }');
$findings = newAnalyzer()->analyzePath($f);
$dead = array_filter($findings, fn ($x) => $x->rule === 'dead-code-after-return');
check('знайдено рівно 1', count($dead) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { echo "ок"; return 1; }');
$findings = newAnalyzer()->analyzePath($f);
$dead = array_filter($findings, fn ($x) => $x->rule === 'dead-code-after-return');
check('return останнім — жодної знахідки', count($dead) === 0);
rrmdir(dirname($f));

// --- Тест 2: empty-catch ---
echo "2. EmptyCatchRule\n";
$f = tempPhpFile('function f() { try { g(); } catch (\Exception $e) { } }');
$findings = newAnalyzer()->analyzePath($f);
$empty = array_filter($findings, fn ($x) => $x->rule === 'empty-catch');
check('порожній catch знайдено', count($empty) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { try { g(); } catch (\Exception $e) { log($e); } }');
$findings = newAnalyzer()->analyzePath($f);
$empty = array_filter($findings, fn ($x) => $x->rule === 'empty-catch');
check('непорожній catch — жодної знахідки', count($empty) === 0);
rrmdir(dirname($f));

$f = tempPhpFile("function f() { try { g(); } catch (\Exception \$e) {\n    // навмисно ігноруємо, помилка тут очікувана\n} }");
$findings = newAnalyzer()->analyzePath($f);
$empty = array_filter($findings, fn ($x) => $x->rule === 'empty-catch');
check('catch лише з коментарем — жодної знахідки (пояснено навмисно)', count($empty) === 0);
rrmdir(dirname($f));

// --- Тест 2б: deep-nesting ---
echo "2б. DeepNestingRule\n";
$f = tempPhpFile('function f() { if (true) { if (true) { if (true) { if (true) { if (true) { echo "глибоко"; } } } } } }');
$findings = newAnalyzer()->analyzePath($f);
$deep = array_filter($findings, fn ($x) => $x->rule === 'deep-nesting');
check('5 рівнів вкладеності — знайдено рівно 1', count($deep) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { if (true) { if (true) { if (true) { if (true) { echo "ще ок"; } } } } }');
$findings = newAnalyzer()->analyzePath($f);
$deep = array_filter($findings, fn ($x) => $x->rule === 'deep-nesting');
check('4 рівні вкладеності — жодної знахідки', count($deep) === 0);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { if (true) { if (true) { if (true) { try { g(); } catch (\Exception $e) { echo "catch не рахується окремо"; } } } } }');
$findings = newAnalyzer()->analyzePath($f);
$deep = array_filter($findings, fn ($x) => $x->rule === 'deep-nesting');
check('CatchClause не додає зайвий рівень поверх TryCatch', count($deep) === 0);
rrmdir(dirname($f));

// --- Тест 2в: empty-function ---
echo "2в. EmptyFunctionRule\n";
$f = tempPhpFile('function f() { }');
$findings = newAnalyzer()->analyzePath($f);
$empty = array_filter($findings, fn ($x) => $x->rule === 'empty-function');
check('порожня функція знайдена', count($empty) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { echo "не порожня"; }');
$findings = newAnalyzer()->analyzePath($f);
$empty = array_filter($findings, fn ($x) => $x->rule === 'empty-function');
check('непорожня функція — жодної знахідки', count($empty) === 0);
rrmdir(dirname($f));

$f = tempPhpFile("function f() {\n    // TODO: реалізувати пізніше, навмисна заглушка\n}");
$findings = newAnalyzer()->analyzePath($f);
$empty = array_filter($findings, fn ($x) => $x->rule === 'empty-function');
check('функція лише з коментарем — жодної знахідки (пояснено навмисно)', count($empty) === 0);
rrmdir(dirname($f));

$f = tempPhpFile('interface I { function f(); }');
$findings = newAnalyzer()->analyzePath($f);
$empty = array_filter($findings, fn ($x) => $x->rule === 'empty-function');
check('метод інтерфейсу без тіла — не помилка (валідна конструкція)', count($empty) === 0);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { $cb = function() {}; return $cb; }');
$findings = newAnalyzer()->analyzePath($f);
$empty = array_filter($findings, fn ($x) => $x->rule === 'empty-function');
check('порожнє анонімне замикання-аргумент — не помилка (майже завжди навмисний no-op)', count($empty) === 0);
rrmdir(dirname($f));

$f = tempPhpFile('class C { function __construct() {} }');
$findings = newAnalyzer()->analyzePath($f);
$empty = array_filter($findings, fn ($x) => $x->rule === 'empty-function');
check('порожній __construct — не помилка (клас без стану)', count($empty) === 0);
rrmdir(dirname($f));

// --- Тест 2г: long-function ---
echo "2г. LongFunctionRule\n";
$f = tempPhpFile('function f() { ' . str_repeat('echo 1;', 31) . ' }');
$findings = newAnalyzer()->analyzePath($f);
$long = array_filter($findings, fn ($x) => $x->rule === 'long-function');
check('31 стейтмент — знайдено рівно 1', count($long) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { ' . str_repeat('echo 1;', 30) . ' }');
$findings = newAnalyzer()->analyzePath($f);
$long = array_filter($findings, fn ($x) => $x->rule === 'long-function');
check('30 стейтментів (межа) — жодної знахідки', count($long) === 0);
rrmdir(dirname($f));

// --- Тест 2ґ: empty-block ---
echo "2ґ. EmptyBlockRule\n";
$f = tempPhpFile('function f() { if (true) { } }');
$findings = newAnalyzer()->analyzePath($f);
$eb = array_filter($findings, fn ($x) => $x->rule === 'empty-block');
check('порожній if знайдено', count($eb) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { for ($i = 0; $i < 10; $i++) { } }');
$findings = newAnalyzer()->analyzePath($f);
$eb = array_filter($findings, fn ($x) => $x->rule === 'empty-block');
check('порожній for знайдено', count($eb) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { while (true) { } }');
$findings = newAnalyzer()->analyzePath($f);
$eb = array_filter($findings, fn ($x) => $x->rule === 'empty-block');
check('порожній while знайдено', count($eb) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { do { } while (true); }');
$findings = newAnalyzer()->analyzePath($f);
$eb = array_filter($findings, fn ($x) => $x->rule === 'empty-block');
check('порожній do-while знайдено', count($eb) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { if (true) { echo "не порожньо"; } }');
$findings = newAnalyzer()->analyzePath($f);
$eb = array_filter($findings, fn ($x) => $x->rule === 'empty-block');
check('непорожній if — жодної знахідки', count($eb) === 0);
rrmdir(dirname($f));

$f = tempPhpFile("function f() { if (true) {\n    // навмисно нічого не робимо тут\n} }");
$findings = newAnalyzer()->analyzePath($f);
$eb = array_filter($findings, fn ($x) => $x->rule === 'empty-block');
check('if лише з коментарем — жодної знахідки (пояснено навмисно)', count($eb) === 0);
rrmdir(dirname($f));

// --- Тест 3: unused-variable ---
echo "3. UnusedVariableRule\n";
$f = tempPhpFile('function f() { $unused = 1; return 2; }');
$findings = newAnalyzer()->analyzePath($f);
$unused = array_filter($findings, fn ($x) => $x->rule === 'unused-variable');
check('невикористана змінна знайдена', count($unused) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('function f() { $used = 1; return $used; }');
$findings = newAnalyzer()->analyzePath($f);
$unused = array_filter($findings, fn ($x) => $x->rule === 'unused-variable');
check('використана змінна — жодної знахідки', count($unused) === 0);
rrmdir(dirname($f));

// --- Тест 4: hardcoded-secret ---
echo "4. HardcodedSecretRule\n";
$f = tempPhpFile('$t = "ghp_' . str_repeat('a', 36) . '";'); // anylint:ignore
$findings = newAnalyzer()->analyzePath($f);
$secrets = array_filter($findings, fn ($x) => $x->rule === 'hardcoded-secret');
check('GitHub-токен знайдено', count($secrets) === 1);
check('значення відредаговано (немає повного токена у повідомленні)', array_reduce(
    $secrets,
    fn ($carry, $x) => $carry && !str_contains($x->message, str_repeat('a', 36)),
    true,
));
rrmdir(dirname($f));

$f = tempPhpFile('$msg = "звичайний рядок без секретів";');
$findings = newAnalyzer()->analyzePath($f);
$secrets = array_filter($findings, fn ($x) => $x->rule === 'hardcoded-secret');
check('звичайний рядок — жодної хибної знахідки', count($secrets) === 0);
rrmdir(dirname($f));

// PHP-масив/JSON-стиль: ключ у лапках і присвоєння через "=>", а не
// голе "password =" — раніше regex вимагав слово одразу перед "="/":",
// тож пропускав саме такий поширений запис.
$f = tempPhpFile("\$config = ['password' => 'RealSecretValue123456'];");
$findings = newAnalyzer()->analyzePath($f);
$secrets = array_filter($findings, fn ($x) => $x->rule === 'hardcoded-secret');
check('PHP-масив \'password\' => \'...\' знайдено', count($secrets) === 1);
rrmdir(dirname($f));

// --- Тест 5: todo-tracker — лише всередині коментарів ---
echo "5. TodoTrackerRule (лише в коментарях, не в ідентифікаторах/рядках)\n";
$f = tempPhpFile("// TODO: зробити пізніше\nfunction f() {}");
$findings = newAnalyzer()->analyzePath($f);
$todos = array_filter($findings, fn ($x) => $x->rule === 'todo-tracker');
check('TODO в // коментарі знайдено', count($todos) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('$todoList = "не todo-коментар, а рядковий літерал";');
$findings = newAnalyzer()->analyzePath($f);
$todos = array_filter($findings, fn ($x) => $x->rule === 'todo-tracker');
check('слово "todo" у рядку/ідентифікаторі НЕ ловиться', count($todos) === 0);
rrmdir(dirname($f));

// URL з "TODO" одразу після "//" (частина схеми http://) - текстовий
// сканер бачить "//" перед "TODO" й раніше плутав це зі справжнім
// коментарем, хоча це звичайний рядковий літерал без жодного коментаря.
$f = tempPhpFile('$url = "http://TODO.example.com/page";');
$findings = newAnalyzer()->analyzePath($f);
$todos = array_filter($findings, fn ($x) => $x->rule === 'todo-tracker');
check('"http://TODO..." у рядку — НЕ хибна знахідка', count($todos) === 0);
rrmdir(dirname($f));

$f = tempPhpFile('$url = "https://TODO.example.com/page";');
$findings = newAnalyzer()->analyzePath($f);
$todos = array_filter($findings, fn ($x) => $x->rule === 'todo-tracker');
check('"https://TODO..." у рядку — НЕ хибна знахідка', count($todos) === 0);
rrmdir(dirname($f));

// --- Тест 5б: замикання — раніше повністю невидимі для структурних
// правил (усортовуючий callback/array_map як окремий стейтмент не
// потрапляв у жодну match-гілку PhpProvider::convertStmt, лишався
// непрозорим "Other" без рекурсії всередину) ---
echo "5б. Структурні правила бачать середину замикань\n";
$f = tempPhpFile('array_map(function ($x) { return $x; echo "мертвий"; }, $a);');
$findings = newAnalyzer()->analyzePath($f);
$dead = array_filter($findings, fn ($x) => $x->rule === 'dead-code-after-return');
check('dead-code у замиканні-аргументі знайдено', count($dead) === 1);
rrmdir(dirname($f));

$f = tempPhpFile('$fn = function () { try { g(); } catch (\Exception $e) { } };');
$findings = newAnalyzer()->analyzePath($f);
$empty = array_filter($findings, fn ($x) => $x->rule === 'empty-catch');
check('empty-catch у замиканні-присвоєнні знайдено', count($empty) === 1);
rrmdir(dirname($f));

// --- Тест 6: синтаксична помилка PHP не валить увесь прогін ---
echo "6. Синтаксична помилка — Finding, не крах\n";
$f = tempPhpFile('function f( {{{ зламаний синтаксис');
$findings = newAnalyzer()->analyzePath($f);
$errors = array_filter($findings, fn ($x) => $x->rule === 'parse-error');
check('parse-error Finding створено', count($errors) === 1);
rrmdir(dirname($f));

// --- Тест 6б: файл без прав на читання - Finding, а не мовчазний
// "ok: true" (раніше file_get_contents() повертав false, Analyzer тихо
// повертав [] - permission-denied файл рахувався "чистим") ---
echo "6б. Непридатний для читання файл — Finding, не мовчазне ok=true\n";
$f = tempPhpFile('function f() { return 1; }');
chmod($f, 0000);
$findings = newAnalyzer()->analyzePath($f);
$unreadable = array_filter($findings, fn ($x) => $x->rule === 'unreadable-file');
check('unreadable-file Finding створено', count($unreadable) === 1);
chmod($f, 0644);
rrmdir(dirname($f));

// --- Тест 7: рекурсивне сканування директорії, пропуск vendor/.git ---
echo "7. Рекурсивне сканування директорії\n";
$dir = sys_get_temp_dir() . '/anylint_dir_' . uniqid('', true);
mkdir($dir . '/src', 0777, true);
mkdir($dir . '/vendor', 0777, true);
file_put_contents($dir . '/src/a.php', "<?php\nfunction f() { return 1; echo 'мертвий'; }");
file_put_contents($dir . '/vendor/b.php', "<?php\nfunction f() { return 1; echo 'має бути пропущено'; }");
$findings = newAnalyzer()->analyzePath($dir);
$dead = array_filter($findings, fn ($x) => $x->rule === 'dead-code-after-return');
check('лише src/ проскановано (1 знахідка, не 2)', count($dead) === 1);
rrmdir($dir);

// --- Тест 7б: пропуск dist/build/target/out (зібрані артефакти, не код) ---
echo "7б. Пропуск dist/build/target/out\n";
$dir = sys_get_temp_dir() . '/anylint_dir_' . uniqid('', true);
mkdir($dir . '/src', 0777, true);
mkdir($dir . '/dist', 0777, true);
file_put_contents($dir . '/src/a.php', "<?php\n// TODO: реальний todo\nfunction f() {}\n");
file_put_contents($dir . '/dist/bundled.js', "// TODO: (комусь-там) зібраний сторонній рантайм, не має вважатись\n");
$findings = newAnalyzer()->analyzePath($dir);
$todos = array_filter($findings, fn ($x) => $x->rule === 'todo-tracker');
check('dist/ пропущено (1 TODO, не 2)', count($todos) === 1);
rrmdir($dir);

// --- Тест 8: CLI окремим процесом ---
echo "8. CLI: --json, exit-коди\n";
function runCli(array $args): array
{
    $command = array_merge([PHP_BINARY, __DIR__ . '/../bin/anylint'], $args);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return [$exitCode, trim((string) $stdout)];
}

$f = tempPhpFile('function f() { return 1; }');
[$exitClean, $outClean] = runCli([$f, '--json']);
$decoded = json_decode($outClean, true);
check('чистий файл: ok=true', $decoded !== null && $decoded['ok'] === true);
check('чистий файл: exit=0', $exitClean === 0);
rrmdir(dirname($f));

$f = tempPhpFile('$t = "ghp_' . str_repeat('a', 36) . '";'); // anylint:ignore
[$exitDirty, $outDirty] = runCli([$f, '--json']);
$decoded = json_decode($outDirty, true);
check('файл із секретом: ok=false', $decoded !== null && $decoded['ok'] === false);
check('файл із секретом: exit=1', $exitDirty === 1);
rrmdir(dirname($f));

[$exitHelp, $outHelp] = runCli(['--help']);
check('--help: exit=0', $exitHelp === 0);
check('--help: показує "anylint"', str_contains($outHelp, 'anylint'));

// --- Тест 8б: невалідний UTF-8 у повідомленні Finding (напр. текст
// TODO-коментаря) НЕ ламає --json мовчки. Раніше json_encode() без
// JSON_INVALID_UTF8_SUBSTITUTE повертав false на невалідному байті -
// --json друкував ПОРОЖНІЙ рядок з exit-кодом 1 (провал є, даних нема) ---
echo "8б. --json переживає невалідний UTF-8 у повідомленні\n";
$dir = sys_get_temp_dir() . '/anylint_utf8_' . uniqid('', true);
mkdir($dir);
$path = $dir . '/test.php';
file_put_contents($path, "<?php\n// TODO: зробити щось \xffпоганим байтом\n");
[$exitBad, $outBad] = runCli([$path, '--json']);
$decoded = json_decode($outBad, true);
check('--json повертає валідний JSON, не порожній рядок', $decoded !== null);
check('--json: findings непорожні (TODO таки знайдено)', $decoded !== null && count($decoded['findings']) === 1);
rrmdir($dir);

// --- Тест 9: NyxilumProvider — доказ крос-мовності: ті самі структурні
// правила ловлять ті самі класи багів у .nx, що й у .php, без жодної
// зміни коду правил. Пропускається, якщо "nx" недоступний (напр. на CI,
// де сестринський репозиторій NyxilumLang не зібраний) - не провал, а
// свідомий skip, як GUI-тести пропускаються в самому NyxilumLang.
echo "9. NyxilumProvider (.nx) - ті самі структурні правила без змін коду\n";
$nxExe = getenv('NX_EXE') ?: 'nx';
// proc_open() повертає false (не resource), якщо виконуваний файл не
// знайдено - passing false у proc_close() кидає TypeError навіть під @
// (той придушує лише warning/notice, не помилки типів), тож перевіряємо
// is_resource() ЯВНО, перш ніж узагалі торкатись $nxProcess.
$nxProcess = @proc_open([$nxExe, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $nxPipes);
$nxAvailable = is_resource($nxProcess) && proc_close($nxProcess) === 0;
if (isset($nxPipes)) {
    foreach ($nxPipes as $p) {
        is_resource($p) && fclose($p);
    }
}

if (!$nxAvailable) {
    echo "  ⏭️  nx недоступний (NX_EXE не задано чи не в PATH) - пропущено\n";
} else {
    function tempNxFile(string $contents): string
    {
        $dir = sys_get_temp_dir() . '/anylint_nx_test_' . uniqid('', true);
        mkdir($dir);
        $path = $dir . '/test.nx';
        file_put_contents($path, $contents);
        return $path;
    }

    $analyzer = (new Analyzer())
        ->withProvider(new NyxilumProvider($nxExe))
        ->withRule(new DeadCodeAfterReturnRule())
        ->withRule(new EmptyCatchRule())
        ->withRule(new TodoTrackerRule());

    $f = tempNxFile("func f() {\n    return 1\n    print(\"мертвий код\")\n}\n");
    $findings = $analyzer->analyzePath($f);
    $dead = array_filter($findings, fn ($x) => $x->rule === 'dead-code-after-return');
    check('dead-code-after-return ловить .nx', count($dead) === 1);
    rrmdir(dirname($f));

    $f = tempNxFile("func f() {\n    try {\n        g()\n    } catch (e) {\n    }\n}\n");
    $findings = $analyzer->analyzePath($f);
    $empty = array_filter($findings, fn ($x) => $x->rule === 'empty-catch');
    check('empty-catch ловить .nx', count($empty) === 1);
    rrmdir(dirname($f));

    $f = tempNxFile("// TODO: додати перевірку\nfunc f() {}\n");
    $findings = $analyzer->analyzePath($f);
    $todos = array_filter($findings, fn ($x) => $x->rule === 'todo-tracker');
    check('todo-tracker ловить .nx (той самий текстовий рушій)', count($todos) === 1);
    rrmdir(dirname($f));

    $f = tempNxFile("func f() {\n    return 1\n}\n");
    $findings = $analyzer->analyzePath($f);
    $dead = array_filter($findings, fn ($x) => $x->rule === 'dead-code-after-return');
    check('чистий .nx-код — жодної хибної знахідки', count($dead) === 0);
    rrmdir(dirname($f));
}

echo "10. JavaScriptProvider/TypeScriptProvider (node dump.js) - ті самі структурні правила без змін коду\n";
$nodeExe = getenv('NODE_EXE') ?: 'node';
$dumpScript = __DIR__ . '/../tools/js-ast-dump/dump.js';
$nodeProcess = @proc_open([$nodeExe, '-e', "require.resolve('typescript', {paths: ['" . dirname($dumpScript) . "']})"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $nodePipes);
$nodeAvailable = is_resource($nodeProcess) && proc_close($nodeProcess) === 0;
if (isset($nodePipes)) {
    foreach ($nodePipes as $p) {
        is_resource($p) && fclose($p);
    }
}

if (!$nodeAvailable) {
    echo "  ⏭️  node/typescript недоступні (node не в PATH або 'npm install' не виконано в tools/js-ast-dump) - пропущено\n";
} else {
    function tempJsFile(string $ext, string $contents): string
    {
        $dir = sys_get_temp_dir() . '/anylint_js_test_' . uniqid('', true);
        mkdir($dir);
        $path = $dir . '/test.' . $ext;
        file_put_contents($path, $contents);
        return $path;
    }

    foreach (['js' => new JavaScriptProvider($nodeExe), 'ts' => new TypeScriptProvider($nodeExe)] as $ext => $provider) {
        $analyzer = (new Analyzer())
            ->withProvider($provider)
            ->withRule(new DeadCodeAfterReturnRule())
            ->withRule(new DeepNestingRule())
            ->withRule(new EmptyBlockRule())
            ->withRule(new EmptyCatchRule())
            ->withRule(new EmptyFunctionRule())
            ->withRule(new LongFunctionRule());

        $f = tempJsFile($ext, "function f() {\n  return 1;\n  console.log('мертвий код');\n}\n");
        $findings = $analyzer->analyzePath($f);
        $dead = array_filter($findings, fn ($x) => $x->rule === 'dead-code-after-return');
        check("dead-code-after-return ловить .{$ext}", count($dead) === 1);
        rrmdir(dirname($f));

        $f = tempJsFile($ext, "function f() {\n  return (() => {\n    return 1;\n    console.log('мертвий код у замиканні');\n  })();\n}\n");
        $findings = $analyzer->analyzePath($f);
        $dead = array_filter($findings, fn ($x) => $x->rule === 'dead-code-after-return');
        check("dead-code-after-return ловить .{$ext} у вкладеній стрілочній функції", count($dead) === 1);
        rrmdir(dirname($f));

        $f = tempJsFile($ext, "function f() {\n  try {\n    g();\n  } catch (e) {\n  }\n}\n");
        $findings = $analyzer->analyzePath($f);
        $empty = array_filter($findings, fn ($x) => $x->rule === 'empty-catch');
        check("empty-catch ловить .{$ext}", count($empty) === 1);
        rrmdir(dirname($f));

        $f = tempJsFile($ext, "function f() {\n  if (a) {\n    if (b) {\n      if (c) {\n        if (d) {\n          if (e) {\n            console.log('глибоко');\n          }\n        }\n      }\n    }\n  }\n}\n");
        $findings = $analyzer->analyzePath($f);
        $deep = array_filter($findings, fn ($x) => $x->rule === 'deep-nesting');
        check("deep-nesting ловить .{$ext}", count($deep) === 1);
        rrmdir(dirname($f));

        $f = tempJsFile($ext, "function f() {\n  if (a) {\n  }\n}\n");
        $findings = $analyzer->analyzePath($f);
        $eb = array_filter($findings, fn ($x) => $x->rule === 'empty-block');
        check("empty-block ловить .{$ext}", count($eb) === 1);
        rrmdir(dirname($f));

        $f = tempJsFile($ext, "function f() {\n}\n");
        $findings = $analyzer->analyzePath($f);
        $empty = array_filter($findings, fn ($x) => $x->rule === 'empty-function');
        check("empty-function ловить .{$ext}", count($empty) === 1);
        rrmdir(dirname($f));

        $f = tempJsFile($ext, "async function f() {\n  await g().catch(() => {});\n}\n");
        $findings = $analyzer->analyzePath($f);
        $empty = array_filter($findings, fn ($x) => $x->rule === 'empty-function');
        check("empty-function не ловить .catch(() => {}) в .{$ext}", count($empty) === 0);
        rrmdir(dirname($f));

        $f = tempJsFile($ext, "function f() {\n" . str_repeat("  console.log(1);\n", 31) . "}\n");
        $findings = $analyzer->analyzePath($f);
        $long = array_filter($findings, fn ($x) => $x->rule === 'long-function');
        check("long-function ловить .{$ext}", count($long) === 1);
        rrmdir(dirname($f));

        $f = tempJsFile($ext, "function f() {\n  return 1;\n}\n");
        $findings = $analyzer->analyzePath($f);
        $dead = array_filter($findings, fn ($x) => $x->rule === 'dead-code-after-return');
        check("чистий .{$ext}-код — жодної хибної знахідки", count($dead) === 0);
        rrmdir(dirname($f));
    }
}

echo "11. tree-sitter-провайдери (C/C++/C#/Java/Python/Rust/Swift/Go/Kotlin/Ruby/Dart/Zig/Objective-C/Solidity) - ті самі структурні правила без змін коду\n";
$treesitterDump = __DIR__ . '/../tools/treesitter-ast-dump/dump.js';
$tsProcess = @proc_open([$nodeExe, '-e', "require.resolve('web-tree-sitter', {paths: ['" . dirname($treesitterDump) . "']})"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tsPipes);
$treesitterAvailable = is_resource($tsProcess) && proc_close($tsProcess) === 0;
if (isset($tsPipes)) {
    foreach ($tsPipes as $p) {
        is_resource($p) && fclose($p);
    }
}

if (!$treesitterAvailable) {
    echo "  ⏭️  node/web-tree-sitter недоступні ('npm install' не виконано в tools/treesitter-ast-dump) - пропущено\n";
} else {
    /** @var array<string, array{ext: string, provider: \AnyLint\LanguageProvider, dead: string, catch: string|null}> $cFamilyCases */
    $cFamilyCases = [
        'C' => [
            'ext' => 'c',
            'provider' => new CProvider($nodeExe),
            'dead' => "int f() {\n  if (1) {\n    return 1;\n    dead();\n  }\n  return 0;\n}\n",
            'catch' => null,
            'clean' => "int f() {\n  if (1) {\n    return 1;\n  }\n  return 0;\n}\n",
        ],
        'C++' => [
            'ext' => 'cpp',
            'provider' => new CppProvider($nodeExe),
            'dead' => "int f() {\n  while (1) {\n    return 1;\n    dead();\n  }\n}\n",
            'catch' => "int f() {\n  try {\n    g();\n  } catch (int e) {\n  }\n  return 0;\n}\n",
            'clean' => "int f() {\n  while (1) {\n    return 1;\n  }\n}\n",
        ],
        'C#' => [
            'ext' => 'cs',
            'provider' => new CSharpProvider($nodeExe),
            'dead' => "class A {\n  int F() {\n    if (x) {\n      return 1;\n      Dead();\n    }\n    return 0;\n  }\n}\n",
            'catch' => "class A {\n  int F() {\n    try {\n      G();\n    } catch (Exception e) {\n    }\n    return 0;\n  }\n}\n",
            'clean' => "class A {\n  int F() {\n    if (x) {\n      return 1;\n    }\n    return 0;\n  }\n}\n",
        ],
        'Java' => [
            'ext' => 'java',
            'provider' => new JavaProvider($nodeExe),
            'dead' => "class A {\n  int f() {\n    for (int i = 0; i < 1; i++) {\n      return 1;\n      dead();\n    }\n    return 0;\n  }\n}\n",
            'catch' => "class A {\n  int f() {\n    try {\n      g();\n    } catch (Exception e) {\n    }\n    return 0;\n  }\n}\n",
            'clean' => "class A {\n  int f() {\n    for (int i = 0; i < 1; i++) {\n      return 1;\n    }\n    return 0;\n  }\n}\n",
        ],
        'Python' => [
            'ext' => 'py',
            'provider' => new PythonProvider($nodeExe),
            'dead' => "def f(x):\n    if x:\n        return 1\n        dead()\n    return 0\n",
            // Порожній except у Python - синтаксична помилка (потрібен хоча б
            // "pass"), тож немає сенсу перевіряти empty-catch тут - на
            // відміну від C-подібних мов, {} завжди валідний.
            'catch' => null,
            'clean' => "def f(x):\n    if x:\n        return 1\n    return 0\n",
        ],
        'Rust' => [
            'ext' => 'rs',
            'provider' => new RustProvider($nodeExe),
            // return у Rust - вираз, обгорнутий в expression_statement;
            // саме цей кейс перевіряє unwrapExpressionStatement() у dump.js.
            'dead' => "fn f(x: i32) -> i32 {\n    if x > 0 {\n        return 1;\n        dead();\n    }\n    0\n}\n",
            // Rust не має try/catch (Result/panic) - структурно нема аналога.
            'catch' => null,
            'clean' => "fn f(x: i32) -> i32 {\n    if x > 0 {\n        return 1;\n    }\n    0\n}\n",
        ],
        'Swift' => [
            'ext' => 'swift',
            'provider' => new SwiftProvider($nodeExe),
            'dead' => "func f(x: Int) -> Int {\n    if x > 0 {\n        return 1\n        dead()\n    }\n    return 0\n}\n",
            'catch' => "func f() {\n    do {\n        try g()\n    } catch {\n    }\n}\n",
            // break/continue - той самий тип вузла control_transfer_statement,
            // що й return; перевіряє, що isReturn() у dump.js справді
            // розрізняє їх за текстом ключового слова, а не хибно ловить усе.
            'clean' => "func f(x: Int) -> Int {\n    for i in 0..<3 {\n        if i == 1 { break }\n        if i == 2 { continue }\n    }\n    return 0\n}\n",
        ],
        'Go' => [
            'ext' => 'go',
            'provider' => new GoProvider($nodeExe),
            'dead' => "func f(x int) int {\n    if x > 0 {\n        return 1\n        dead()\n    }\n    return 0\n}\n",
            // Go не має try/catch (defer/recover) - структурно нема аналога.
            'catch' => null,
            'clean' => "func f(x int) int {\n    if x > 0 {\n        return 1\n    }\n    return 0\n}\n",
        ],
        'Kotlin' => [
            'ext' => 'kt',
            'provider' => new KotlinProvider($nodeExe),
            'dead' => "fun f(x: Int): Int {\n    if (x > 0) {\n        return 1\n        dead()\n    }\n    return 0\n}\n",
            'catch' => "fun f() {\n    try {\n        g()\n    } catch (e: Exception) {\n    }\n}\n",
            // break/continue - той самий тип вузла jump_expression, що й
            // return; перевіряє, що isReturn() у dump.js справді розрізняє
            // їх за текстом ключового слова, а не хибно ловить усе.
            'clean' => "fun f(x: Int): Int {\n    for (i in 0..1) {\n        if (i == 1) { break }\n        if (i == 2) { continue }\n    }\n    return 0\n}\n",
        ],
        'Ruby' => [
            'ext' => 'rb',
            'provider' => new RubyProvider($nodeExe),
            'dead' => "def f(x)\n  if x\n    return 1\n    dead\n  end\n  return 0\nend\n",
            'catch' => "def f\n  begin\n    g\n  rescue => e\n  end\nend\n",
            // ensure-гілка НЕ повинна злитись у той самий "блок", що й
            // try-тіло (mapTryCatchChildren() у dump.js) - інакше return у
            // begin з подальшим ensure-кодом хибно виглядав би як мертвий
            // код одразу після return.
            'clean' => "def f(x)\n  if x\n    return 1\n  end\n  begin\n    return 1\n  rescue => e\n    handle(e)\n  ensure\n    cleanup\n  end\n  return 0\nend\n",
        ],
        'Dart' => [
            'ext' => 'dart',
            'provider' => new DartProvider($nodeExe),
            'dead' => "int f(int x) {\n  if (x > 0) {\n    return 1;\n    dead();\n  }\n  return 0;\n}\n",
            // Тіло catch у Dart - сусід catch_clause, а не його дитина
            // (mapTryCatchChildren() у dump.js) - саме цей кейс перевіряє.
            'catch' => "int f() {\n  try {\n    g();\n  } catch (e) {\n  }\n  return 0;\n}\n",
            'clean' => "int f(int x) {\n  if (x > 0) {\n    return 1;\n  }\n  return 0;\n}\n",
        ],
        'Zig' => [
            'ext' => 'zig',
            'provider' => new ZigProvider($nodeExe),
            // return у Zig, так само як у Rust, - return_expression,
            // обгорнутий в expression_statement.
            'dead' => "fn f(x: i32) i32 {\n    if (x > 0) {\n        return 1;\n        dead();\n    }\n    return 0;\n}\n",
            // Zig не має традиційного try/catch-блоку (catch - вираз-оператор).
            'catch' => null,
            'clean' => "fn f(x: i32) i32 {\n    if (x > 0) {\n        return 1;\n    }\n    return 0;\n}\n",
        ],
        'Objective-C' => [
            'ext' => 'm',
            'provider' => new ObjectiveCProvider($nodeExe),
            'dead' => "@implementation A\n- (int)f:(int)x {\n    if (x > 0) {\n        return 1;\n        dead();\n    }\n    return 0;\n}\n@end\n",
            'catch' => "@implementation A\n- (void)f {\n    @try {\n        g();\n    } @catch (NSException *e) {\n    }\n}\n@end\n",
            'clean' => "@implementation A\n- (int)f:(int)x {\n    if (x > 0) {\n        return 1;\n    }\n    return 0;\n}\n@end\n",
        ],
        'Solidity' => [
            'ext' => 'sol',
            'provider' => new SolidityProvider($nodeExe),
            'dead' => "contract A {\n  function f(uint x) public returns (uint) {\n    if (x > 0) {\n      return 1;\n      dead();\n    }\n    return 0;\n  }\n}\n",
            'catch' => "contract A {\n  function f() public {\n    try foo.bar() {\n    } catch {\n    }\n  }\n}\n",
            'clean' => "contract A {\n  function f(uint x) public returns (uint) {\n    if (x > 0) {\n      return 1;\n    }\n    return 0;\n  }\n}\n",
        ],
    ];

    foreach ($cFamilyCases as $langName => $case) {
        $analyzer = (new Analyzer())
            ->withProvider($case['provider'])
            ->withRule(new DeadCodeAfterReturnRule())
            ->withRule(new EmptyCatchRule());

        $f = tempJsFile($case['ext'], $case['dead']);
        $findings = $analyzer->analyzePath($f);
        $dead = array_filter($findings, fn ($x) => $x->rule === 'dead-code-after-return');
        check("dead-code-after-return ловить {$langName}", count($dead) === 1);
        rrmdir(dirname($f));

        if ($case['catch'] !== null) {
            $f = tempJsFile($case['ext'], $case['catch']);
            $findings = $analyzer->analyzePath($f);
            $empty = array_filter($findings, fn ($x) => $x->rule === 'empty-catch');
            check("empty-catch ловить {$langName}", count($empty) === 1);
            rrmdir(dirname($f));
        }

        $f = tempJsFile($case['ext'], $case['clean']);
        $findings = $analyzer->analyzePath($f);
        $dead = array_filter($findings, fn ($x) => $x->rule === 'dead-code-after-return');
        check("чистий {$langName}-код — жодної хибної знахідки", count($dead) === 0);
        rrmdir(dirname($f));
    }
}

echo "\n======================================\n";
echo "Успішно: {$passed} | Провалено: {$failures}\n";

if ($failures > 0) {
    echo "Є провалені тести.\n";
    exit(1);
}

echo "Усі тести пройдено.\n";
exit(0);
