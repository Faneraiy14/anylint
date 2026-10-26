<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Analyzer;
use AnyLint\Providers\PythonProvider;
use AnyLint\Rules\EmptyBlockRule;
use AnyLint\Rules\EmptyCatchRule;
use AnyLint\Rules\EmptyFunctionRule;

/**
 * "except: pass" - Python-форма порожнього catch (порожній except -
 * синтаксична помилка, тож pass там лише задовольняє граматику). dump.js
 * викидає pass ЛИШЕ з тіла except (catchNoopStmt), тож пояснювальний
 * коментар і далі знімає знахідку, а "def f(): pass" не стає
 * empty-function. Той самий запобіжник доступності node/web-tree-sitter,
 * що й у TreeSitterProvidersTest.
 */
final class PythonQuirksTest extends AnalyzerTestCase
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
            ->withProvider(new PythonProvider(self::$nodeExe))
            ->withRule(new EmptyCatchRule())
            ->withRule(new EmptyBlockRule())
            ->withRule(new EmptyFunctionRule());
    }

    /** @return iterable<string, array{string}> */
    public static function swallowingExcepts(): iterable
    {
        yield 'typed' => ["try:\n    f()\nexcept ValueError:\n    pass\n"];
        yield 'bare' => ["try:\n    f()\nexcept:\n    pass\n"];
        yield 'tuple with alias and finally' => ["try:\n    f()\nexcept (A, B) as e:\n    pass\nfinally:\n    g()\n"];
    }

    /** @dataProvider swallowingExcepts */
    public function testExceptPassIsAnEmptyCatch(string $code): void
    {
        $f = $this->tempFile('py', $code);
        $this->assertCount(1, $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-catch'));
    }

    /** @return iterable<string, array{string}> */
    public static function intentionalExcepts(): iterable
    {
        yield 'comment after pass' => ["try:\n    f()\nexcept ValueError:\n    pass  # expected\n"];
        // Цей коментар tree-sitter кладе в except_clause, а не в блок.
        yield 'comment above pass' => ["try:\n    f()\nexcept ValueError:\n    # expected\n    pass\n"];
        yield 'comment on except line' => ["try:\n    f()\nexcept ValueError:  # best effort\n    pass\n"];
        yield 'real handling' => ["try:\n    f()\nexcept ValueError:\n    log()\n    pass\n"];
    }

    /** @dataProvider intentionalExcepts */
    public function testExplainedOrHandledExceptIsNotAFinding(string $code): void
    {
        $f = $this->tempFile('py', $code);
        $this->assertSame([], $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-catch'));
    }

    public function testPassOutsideExceptIsStillAStatement(): void
    {
        // "def hook(self): pass" - ідіома (абстрактні методи, хуки), не
        // забута реалізація; pass викидається лише з except.
        $f = $this->tempFile('py', "class A:\n    def hook(self):\n        pass\n\nif x:\n    pass\n");
        $this->assertSame([], $this->analyzer()->analyzePath($f));
    }
}
