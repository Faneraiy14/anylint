<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Analyzer;
use AnyLint\Rules\DeadCodeAfterReturnRule;
use AnyLint\Rules\EmptyCatchRule;
use AnyLint\Rules\EmptyFunctionRule;
use AnyLint\Rules\LongFunctionRule;

/**
 * tree-sitter-провайдери (C/C++/C#/Java/Python/Rust/Swift/Go/Kotlin/Ruby/
 * Dart/Zig/Objective-C/Solidity) - ті самі структурні правила без змін
 * коду. Пропускається, якщо node/web-tree-sitter недоступні ('npm
 * install' не виконано в tools/treesitter-ast-dump).
 *
 * Кожен елемент data provider - чисті дані (клас провайдера як рядок,
 * розширення файлу, фрагменти коду) - самі об'єкти-провайдери
 * інстанціюються в тілі тесту, бо потребують $nodeExe, відомого лише в
 * рантаймі (setUpBeforeClass), не на етапі збору даних.
 */
final class TreeSitterProvidersTest extends AnalyzerTestCase
{
    private static ?string $nodeExe = null;

    public static function setUpBeforeClass(): void
    {
        $nodeExe = getenv('NODE_EXE') ?: 'node';
        $dump = __DIR__ . '/../tools/treesitter-ast-dump/dump.js';
        $process = @proc_open(
            [$nodeExe, '-e', "require.resolve('web-tree-sitter', {paths: ['" . dirname($dump) . "']})"],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $available = is_resource($process) && proc_close($process) === 0;
        if (isset($pipes)) {
            foreach ($pipes as $p) {
                is_resource($p) && fclose($p);
            }
        }
        self::$nodeExe = $available ? $nodeExe : null;
    }

    /**
     * @return iterable<string, array{string, string, array<string, string|null>}>
     */
    public static function languageProvider(): iterable
    {
        $cases = [
            'C' => [
                'class' => \AnyLint\Providers\CProvider::class,
                'ext' => 'c',
                'dead' => "int f() {\n  if (1) {\n    return 1;\n    dead();\n  }\n  return 0;\n}\n",
                'catch' => null,
                'emptyFunc' => "void f() {\n}\n",
                'longFunc' => "void f() {\n" . str_repeat("  g();\n", 31) . "}\n",
                'clean' => "int f() {\n  if (1) {\n    return 1;\n  }\n  return 0;\n}\n",
            ],
            'C++' => [
                'class' => \AnyLint\Providers\CppProvider::class,
                'ext' => 'cpp',
                'dead' => "int f() {\n  while (1) {\n    return 1;\n    dead();\n  }\n}\n",
                'catch' => "int f() {\n  try {\n    g();\n  } catch (int e) {\n  }\n  return 0;\n}\n",
                'emptyFunc' => "void f() {\n}\n",
                'clean' => "int f() {\n  while (1) {\n    return 1;\n  }\n}\n",
            ],
            'C#' => [
                'class' => \AnyLint\Providers\CSharpProvider::class,
                'ext' => 'cs',
                'dead' => "class A {\n  int F() {\n    if (x) {\n      return 1;\n      Dead();\n    }\n    return 0;\n  }\n}\n",
                'catch' => "class A {\n  int F() {\n    try {\n      G();\n    } catch (Exception e) {\n    }\n    return 0;\n  }\n}\n",
                'emptyFunc' => "class A {\n  void F() {\n  }\n}\n",
                'noFalsePositive' => "interface I {\n  void F();\n}\n",
                'clean' => "class A {\n  int F() {\n    if (x) {\n      return 1;\n    }\n    return 0;\n  }\n}\n",
            ],
            'Java' => [
                'class' => \AnyLint\Providers\JavaProvider::class,
                'ext' => 'java',
                'dead' => "class A {\n  int f() {\n    for (int i = 0; i < 1; i++) {\n      return 1;\n      dead();\n    }\n    return 0;\n  }\n}\n",
                'catch' => "class A {\n  int f() {\n    try {\n      g();\n    } catch (Exception e) {\n    }\n    return 0;\n  }\n}\n",
                'emptyFunc' => "class A {\n  void f() {\n  }\n}\n",
                'noFalsePositive' => "interface I {\n  void f();\n}\n",
                'longFunc' => "class A {\n  void f() {\n" . str_repeat("    g();\n", 31) . "  }\n}\n",
                'clean' => "class A {\n  int f() {\n    for (int i = 0; i < 1; i++) {\n      return 1;\n    }\n    return 0;\n  }\n}\n",
            ],
            'Python' => [
                'class' => \AnyLint\Providers\PythonProvider::class,
                'ext' => 'py',
                'dead' => "def f(x):\n    if x:\n        return 1\n        dead()\n    return 0\n",
                // Порожній except у Python - синтаксична помилка (потрібен
                // хоча б "pass"), тож немає сенсу перевіряти empty-catch.
                'catch' => null,
                // "pass" - ЄДИНИЙ синтаксично валідний спосіб написати
                // порожнє тіло функції в Python і це РЕАЛЬНИЙ стейтмент в
                // AST - empty-function структурно не може спрацювати тут.
                'clean' => "def f(x):\n    if x:\n        return 1\n    return 0\n",
            ],
            'Rust' => [
                'class' => \AnyLint\Providers\RustProvider::class,
                'ext' => 'rs',
                // return у Rust - вираз, обгорнутий в expression_statement.
                'dead' => "fn f(x: i32) -> i32 {\n    if x > 0 {\n        return 1;\n        dead();\n    }\n    0\n}\n",
                // Rust не має try/catch (Result/panic) - структурно нема аналога.
                'catch' => null,
                'emptyFunc' => "fn f() {\n}\n",
                'clean' => "fn f(x: i32) -> i32 {\n    if x > 0 {\n        return 1;\n    }\n    0\n}\n",
            ],
            'Swift' => [
                'class' => \AnyLint\Providers\SwiftProvider::class,
                'ext' => 'swift',
                'dead' => "func f(x: Int) -> Int {\n    if x > 0 {\n        return 1\n        dead()\n    }\n    return 0\n}\n",
                'catch' => "func f() {\n    do {\n        try g()\n    } catch {\n    }\n}\n",
                'emptyFunc' => "func f() {\n}\n",
                // break/continue - той самий тип вузла що й return; перевіряє,
                // що isReturn() у dump.js справді розрізняє їх за ключовим словом.
                'clean' => "func f(x: Int) -> Int {\n    for i in 0..<3 {\n        if i == 1 { break }\n        if i == 2 { continue }\n    }\n    return 0\n}\n",
            ],
            'Go' => [
                'class' => \AnyLint\Providers\GoProvider::class,
                'ext' => 'go',
                'dead' => "func f(x int) int {\n    if x > 0 {\n        return 1\n        dead()\n    }\n    return 0\n}\n",
                // Go не має try/catch (defer/recover) - структурно нема аналога.
                'catch' => null,
                'emptyFunc' => "func f() {\n}\n",
                'clean' => "func f(x int) int {\n    if x > 0 {\n        return 1\n    }\n    return 0\n}\n",
            ],
            'Kotlin' => [
                'class' => \AnyLint\Providers\KotlinProvider::class,
                'ext' => 'kt',
                'dead' => "fun f(x: Int): Int {\n    if (x > 0) {\n        return 1\n        dead()\n    }\n    return 0\n}\n",
                'catch' => "fun f() {\n    try {\n        g()\n    } catch (e: Exception) {\n    }\n}\n",
                'emptyFunc' => "fun f() {\n}\n",
                'clean' => "fun f(x: Int): Int {\n    for (i in 0..1) {\n        if (i == 1) { break }\n        if (i == 2) { continue }\n    }\n    return 0\n}\n",
            ],
            'Ruby' => [
                'class' => \AnyLint\Providers\RubyProvider::class,
                'ext' => 'rb',
                'dead' => "def f(x)\n  if x\n    return 1\n    dead\n  end\n  return 0\nend\n",
                'catch' => "def f\n  begin\n    g\n  rescue => e\n  end\nend\n",
                'emptyFunc' => "def f\nend\n",
                // ensure-гілка НЕ повинна злитись у той самий "блок", що й
                // try-тіло - інакше return у begin з подальшим ensure-кодом
                // хибно виглядав би як мертвий код одразу після return.
                'clean' => "def f(x)\n  if x\n    return 1\n  end\n  begin\n    return 1\n  rescue => e\n    handle(e)\n  ensure\n    cleanup\n  end\n  return 0\nend\n",
            ],
            'Dart' => [
                'class' => \AnyLint\Providers\DartProvider::class,
                'ext' => 'dart',
                'dead' => "int f(int x) {\n  if (x > 0) {\n    return 1;\n    dead();\n  }\n  return 0;\n}\n",
                // Тіло catch у Dart - сусід catch_clause, а не його дитина.
                'catch' => "int f() {\n  try {\n    g();\n  } catch (e) {\n  }\n  return 0;\n}\n",
                // Клас-метод, не топ-рівнева функція: тіло класу обгортає
                // function_signature ще одним вузлом method_signature.
                'emptyFunc' => "class A {\n  void f() {\n  }\n}\n",
                'longFunc' => "class A {\n  void f() {\n" . str_repeat("    g();\n", 31) . "  }\n}\n",
                'clean' => "int f(int x) {\n  if (x > 0) {\n    return 1;\n  }\n  return 0;\n}\n",
            ],
            'Zig' => [
                'class' => \AnyLint\Providers\ZigProvider::class,
                'ext' => 'zig',
                // return у Zig, так само як у Rust, - return_expression.
                'dead' => "fn f(x: i32) i32 {\n    if (x > 0) {\n        return 1;\n        dead();\n    }\n    return 0;\n}\n",
                // Zig не має традиційного try/catch-блоку (catch - вираз-оператор).
                'catch' => null,
                'emptyFunc' => "fn f() void {\n}\n",
                'clean' => "fn f(x: i32) i32 {\n    if (x > 0) {\n        return 1;\n    }\n    return 0;\n}\n",
            ],
            'Lua' => [
                'class' => \AnyLint\Providers\LuaProvider::class,
                'ext' => 'lua',
                // Lua вимагає, щоб return був ОСТАННІМ стейтментом свого
                // блоку (синтаксична помилка, не стиль) - ця конкретна
                // перевірка структурно не застосовна тут, покрита окремо в
                // LuaQuirksTest разом із доказом самої заборони.
                'dead' => null,
                // pcall/xpcall - звичайні виклики функцій, не мовна конструкція.
                'catch' => null,
                // Lua не має фігурних дужок узагалі, тіло синтезується завжди.
                'emptyFunc' => "function f()\nend\n",
                'longFunc' => "function f()\n" . str_repeat("  g()\n", 31) . "end\n",
                'clean' => "function f(x)\n  if x > 0 then\n    return 1\n  end\n  return 0\nend\n",
            ],
            'Objective-C' => [
                'class' => \AnyLint\Providers\ObjectiveCProvider::class,
                'ext' => 'm',
                'dead' => "@implementation A\n- (int)f:(int)x {\n    if (x > 0) {\n        return 1;\n        dead();\n    }\n    return 0;\n}\n@end\n",
                'catch' => "@implementation A\n- (void)f {\n    @try {\n        g();\n    } @catch (NSException *e) {\n    }\n}\n@end\n",
                'clean' => "@implementation A\n- (int)f:(int)x {\n    if (x > 0) {\n        return 1;\n    }\n    return 0;\n}\n@end\n",
            ],
            'Solidity' => [
                'class' => \AnyLint\Providers\SolidityProvider::class,
                'ext' => 'sol',
                'dead' => "contract A {\n  function f(uint x) public returns (uint) {\n    if (x > 0) {\n      return 1;\n      dead();\n    }\n    return 0;\n  }\n}\n",
                'catch' => "contract A {\n  function f() public {\n    try foo.bar() {\n    } catch {\n    }\n  }\n}\n",
                'emptyFunc' => "contract A {\n  function f() public {\n  }\n}\n",
                'clean' => "contract A {\n  function f(uint x) public returns (uint) {\n    if (x > 0) {\n      return 1;\n    }\n    return 0;\n  }\n}\n",
            ],
        ];

        foreach ($cases as $name => $case) {
            yield $name => [$case['class'], $case['ext'], $case];
        }
    }

    /**
     * @param array<string, string|null> $case
     */
    private function analyzerFor(string $providerClass, string $nodeExe): Analyzer
    {
        return (new Analyzer())
            ->withProvider(new $providerClass($nodeExe))
            ->withRule(new DeadCodeAfterReturnRule())
            ->withRule(new EmptyCatchRule())
            ->withRule(new EmptyFunctionRule())
            ->withRule(new LongFunctionRule());
    }

    /**
     * @dataProvider languageProvider
     * @param array<string, string|null> $case
     */
    public function testStructuralRulesForLanguage(string $providerClass, string $ext, array $case): void
    {
        if (self::$nodeExe === null) {
            $this->markTestSkipped("node/web-tree-sitter недоступні ('npm install' не виконано в tools/treesitter-ast-dump)");
        }
        $analyzer = $this->analyzerFor($providerClass, self::$nodeExe);

        if ($case['dead'] !== null) {
            $f = $this->tempFile($ext, $case['dead']);
            $dead = $this->findingsFor($analyzer->analyzePath($f), 'dead-code-after-return');
            $this->assertCount(1, $dead, "dead-code-after-return should catch {$ext}");
        }

        if (($case['catch'] ?? null) !== null) {
            $f = $this->tempFile($ext, $case['catch']);
            $empty = $this->findingsFor($analyzer->analyzePath($f), 'empty-catch');
            $this->assertCount(1, $empty, "empty-catch should catch {$ext}");
        }

        if (($case['emptyFunc'] ?? null) !== null) {
            $f = $this->tempFile($ext, $case['emptyFunc']);
            $emptyFunc = $this->findingsFor($analyzer->analyzePath($f), 'empty-function');
            $this->assertCount(1, $emptyFunc, "empty-function should catch {$ext}");
        }

        if (($case['noFalsePositive'] ?? null) !== null) {
            $f = $this->tempFile($ext, $case['noFalsePositive']);
            $emptyFunc = $this->findingsFor($analyzer->analyzePath($f), 'empty-function');
            $this->assertCount(0, $emptyFunc, "empty-function should NOT flag a bodyless method in {$ext}");
        }

        if (($case['longFunc'] ?? null) !== null) {
            $f = $this->tempFile($ext, $case['longFunc']);
            $long = $this->findingsFor($analyzer->analyzePath($f), 'long-function');
            $this->assertCount(1, $long, "long-function should catch {$ext}");
        }

        $f = $this->tempFile($ext, $case['clean']);
        $dead = $this->findingsFor($analyzer->analyzePath($f), 'dead-code-after-return');
        $this->assertCount(0, $dead, "clean {$ext} code should produce no false positive");
    }
}
