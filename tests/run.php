<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AnyLint\Analyzer;
use AnyLint\Providers\NyxilumProvider;
use AnyLint\Providers\PhpProvider;
use AnyLint\Rules\DeadCodeAfterReturnRule;
use AnyLint\Rules\EmptyCatchRule;
use AnyLint\Rules\HardcodedSecretRule;
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
        ->withRule(new EmptyCatchRule())
        ->withRule(new HardcodedSecretRule())
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

echo "\n======================================\n";
echo "Успішно: {$passed} | Провалено: {$failures}\n";

if ($failures > 0) {
    echo "Є провалені тести.\n";
    exit(1);
}

echo "Усі тести пройдено.\n";
exit(0);
