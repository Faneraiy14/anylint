<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use AnyLint\Analyzer;
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

// --- Тест 6: синтаксична помилка PHP не валить увесь прогін ---
echo "6. Синтаксична помилка — Finding, не крах\n";
$f = tempPhpFile('function f( {{{ зламаний синтаксис');
$findings = newAnalyzer()->analyzePath($f);
$errors = array_filter($findings, fn ($x) => $x->rule === 'parse-error');
check('parse-error Finding створено', count($errors) === 1);
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

echo "\n======================================\n";
echo "Успішно: {$passed} | Провалено: {$failures}\n";

if ($failures > 0) {
    echo "Є провалені тести.\n";
    exit(1);
}

echo "Усі тести пройдено.\n";
exit(0);
