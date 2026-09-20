<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Analyzer;
use AnyLint\Providers\LuaProvider;
use AnyLint\Rules\DeadCodeAfterReturnRule;
use AnyLint\Rules\DeepNestingRule;
use AnyLint\Rules\EmptyBlockRule;

/**
 * LuaProvider - специфічні квірки (порожні тіла без вузла block, do/
 * repeat, числовий/generic for), жоден з яких не має аналога в решті
 * tree-sitter-провайдерів, тож перевіряється окремо від спільного циклу
 * в TreeSitterProvidersTest. Той самий запобіжник доступності node/
 * web-tree-sitter, що й там.
 */
final class LuaQuirksTest extends AnalyzerTestCase
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

    private function analyzer(): Analyzer
    {
        if (self::$nodeExe === null) {
            $this->markTestSkipped("node/web-tree-sitter недоступні ('npm install' не виконано в tools/treesitter-ast-dump)");
        }
        return (new Analyzer())
            ->withProvider(new LuaProvider(self::$nodeExe))
            ->withRule(new EmptyBlockRule())
            ->withRule(new DeepNestingRule())
            ->withRule(new DeadCodeAfterReturnRule());
    }

    public function testEmptyIfWithoutABlockNodeInTheRawTreeIsFound(): void
    {
        // Lua взагалі не породжує вузол block, коли тіло порожнє (на
        // відміну від C-подібних мов) - controlBodyIsImplicit у dump.js
        // синтезує його.
        $f = $this->tempFile('lua', "function f(x)\n  if x then\n  end\nend\n");
        $eb = $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(1, $eb);
    }

    public function testEmptyWhileIsFound(): void
    {
        $f = $this->tempFile('lua', "function f()\n  while true do\n  end\nend\n");
        $eb = $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(1, $eb);
    }

    public function testEmptyNumericForForNumericStatementIsFound(): void
    {
        $f = $this->tempFile('lua', "function f()\n  for i = 1, 10 do\n  end\nend\n");
        $eb = $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(1, $eb);
    }

    public function testEmptyGenericForInIsFound(): void
    {
        $f = $this->tempFile('lua', "function f(t)\n  for k, v in pairs(t) do\n  end\nend\n");
        $eb = $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(1, $eb);
    }

    public function testEmptyRepeatUntilMappedToDoIsFound(): void
    {
        $f = $this->tempFile('lua', "function f()\n  repeat\n  until true\nend\n");
        $eb = $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(1, $eb);
    }

    public function testEmptyDoEndIsNotAControlConstructAndNotAFinding(): void
    {
        // "do ... end" у Lua - НЕ цикл (на відміну від решти мов), а
        // простий скоуп-блок, типово для раннього return. Порожній
        // "do end" НЕ повинен ловитись empty-block, і код ПІСЛЯ такого
        // do-блоку - НЕ мертвий код (return усередині нього не "видно"
        // зовні, це інший блок).
        $f = $this->tempFile('lua', "function f()\n  do\n  end\n  print(\"не мертвий код\")\nend\n");
        $findings = $this->analyzer()->analyzePath($f);
        $eb = $this->findingsFor($findings, 'empty-block');
        $dead = $this->findingsFor($findings, 'dead-code-after-return');
        $this->assertCount(0, $eb);
        $this->assertCount(0, $dead);
    }

    public function testReturnInsideDoEndDoesNotProduceAFalseDeadCodeAfterItsBlock(): void
    {
        $f = $this->tempFile('lua', "function f(x)\n  do\n    return x\n  end\n  print(\"насправді недосяжно, але це інший блок - правило про це навмисно мовчить\")\nend\n");
        $dead = $this->findingsFor($this->analyzer()->analyzePath($f), 'dead-code-after-return');
        $this->assertCount(0, $dead);
    }

    public function testCodeRightAfterReturnInTheSameBlockIsALuaSyntaxErrorNotAStyleIssue(): void
    {
        // Доказ, чому 'dead' => null для Lua в TreeSitterProvidersTest:
        // код одразу після return у тому самому блоці - не просто стиль,
        // а реальна синтаксична помилка мови (return зобов'язаний бути
        // останнім стейтментом блоку) - Lua ловить це на етапі парсингу
        // сам, без допомоги лінтера.
        $f = $this->tempFile('lua', "function f(x)\n  return 1\n  dead()\nend\n");
        $parseErr = $this->findingsFor($this->analyzer()->analyzePath($f), 'parse-error');
        $this->assertCount(1, $parseErr);
    }
}
