<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Analyzer;
use AnyLint\Providers\JavaScriptProvider;
use AnyLint\Providers\TypeScriptProvider;
use AnyLint\Rules\DeadCodeAfterReturnRule;
use AnyLint\Rules\DeepNestingRule;
use AnyLint\Rules\EmptyBlockRule;
use AnyLint\Rules\EmptyCatchRule;
use AnyLint\Rules\EmptyFunctionRule;
use AnyLint\Rules\LongFunctionRule;

/**
 * node dump.js - ті самі структурні правила без змін коду. Пропускається,
 * якщо node/typescript недоступні ('npm install' не виконано в
 * tools/js-ast-dump).
 */
final class JsTypeScriptProviderTest extends AnalyzerTestCase
{
    private static ?string $nodeExe = null;

    public static function setUpBeforeClass(): void
    {
        $nodeExe = getenv('NODE_EXE') ?: 'node';
        $dumpScript = __DIR__ . '/../tools/js-ast-dump/dump.js';
        $process = @proc_open(
            [$nodeExe, '-e', "require.resolve('typescript', {paths: ['" . dirname($dumpScript) . "']})"],
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

    /** @return iterable<string, array{string}> */
    public static function extensionProvider(): iterable
    {
        yield 'JavaScript' => ['js'];
        yield 'TypeScript' => ['ts'];
    }

    private function analyzerFor(string $ext): Analyzer
    {
        if (self::$nodeExe === null) {
            $this->markTestSkipped("node/typescript недоступні (node не в PATH або 'npm install' не виконано в tools/js-ast-dump)");
        }
        $provider = $ext === 'ts' ? new TypeScriptProvider(self::$nodeExe) : new JavaScriptProvider(self::$nodeExe);
        return (new Analyzer())
            ->withProvider($provider)
            ->withRule(new DeadCodeAfterReturnRule())
            ->withRule(new DeepNestingRule())
            ->withRule(new EmptyBlockRule())
            ->withRule(new EmptyCatchRule())
            ->withRule(new EmptyFunctionRule())
            ->withRule(new LongFunctionRule());
    }

    /** @dataProvider extensionProvider */
    public function testDeadCodeAfterReturnIsCaught(string $ext): void
    {
        $f = $this->tempFile($ext, "function f() {\n  return 1;\n  console.log('мертвий код');\n}\n");
        $dead = $this->findingsFor($this->analyzerFor($ext)->analyzePath($f), 'dead-code-after-return');
        $this->assertCount(1, $dead);
    }

    /** @dataProvider extensionProvider */
    public function testDeadCodeAfterReturnIsCaughtInsideANestedArrowFunction(string $ext): void
    {
        $f = $this->tempFile($ext, "function f() {\n  return (() => {\n    return 1;\n    console.log('мертвий код у замиканні');\n  })();\n}\n");
        $dead = $this->findingsFor($this->analyzerFor($ext)->analyzePath($f), 'dead-code-after-return');
        $this->assertCount(1, $dead);
    }

    /** @dataProvider extensionProvider */
    public function testEmptyCatchIsCaught(string $ext): void
    {
        $f = $this->tempFile($ext, "function f() {\n  try {\n    g();\n  } catch (e) {\n  }\n}\n");
        $empty = $this->findingsFor($this->analyzerFor($ext)->analyzePath($f), 'empty-catch');
        $this->assertCount(1, $empty);
    }

    /** @dataProvider extensionProvider */
    public function testDeepNestingIsCaught(string $ext): void
    {
        $f = $this->tempFile($ext, "function f() {\n  if (a) {\n    if (b) {\n      if (c) {\n        if (d) {\n          if (e) {\n            console.log('глибоко');\n          }\n        }\n      }\n    }\n  }\n}\n");
        $deep = $this->findingsFor($this->analyzerFor($ext)->analyzePath($f), 'deep-nesting');
        $this->assertCount(1, $deep);
    }

    /** @dataProvider extensionProvider */
    public function testAFlatElseIfChainIsNotCountedAsNesting(string $ext): void
    {
        // "else if" - плаский ланцюжок умов, а не вкладеність: JS/TS
        // представляють кожну ланку як If, вкладений в If без Block
        // навколо, тож без спеціальної обробки лічильник рахував би
        // кожну гілку ланцюжка як +1 рівень.
        $f = $this->tempFile($ext, "function f() {\n  if (a) {\n  } else if (b) {\n  } else if (c) {\n  } else if (d) {\n  } else if (e) {\n  } else if (g) {\n  } else if (h) {\n  } else if (i) {\n  }\n}\n");
        $deep = $this->findingsFor($this->analyzerFor($ext)->analyzePath($f), 'deep-nesting');
        $this->assertCount(0, $deep);
    }

    /** @dataProvider extensionProvider */
    public function testEmptyBlockIsCaught(string $ext): void
    {
        $f = $this->tempFile($ext, "function f() {\n  if (a) {\n  }\n}\n");
        $eb = $this->findingsFor($this->analyzerFor($ext)->analyzePath($f), 'empty-block');
        $this->assertCount(1, $eb);
    }

    /** @dataProvider extensionProvider */
    public function testEmptyFunctionIsCaught(string $ext): void
    {
        $f = $this->tempFile($ext, "function f() {\n}\n");
        $empty = $this->findingsFor($this->analyzerFor($ext)->analyzePath($f), 'empty-function');
        $this->assertCount(1, $empty);
    }

    /** @dataProvider extensionProvider */
    public function testEmptyCatchCallbackIsNotFlaggedAsAnEmptyFunction(string $ext): void
    {
        $f = $this->tempFile($ext, "async function f() {\n  await g().catch(() => {});\n}\n");
        $empty = $this->findingsFor($this->analyzerFor($ext)->analyzePath($f), 'empty-function');
        $this->assertCount(0, $empty);
    }

    /** @dataProvider extensionProvider */
    public function testLongFunctionIsCaught(string $ext): void
    {
        $f = $this->tempFile($ext, "function f() {\n" . str_repeat("  console.log(1);\n", 31) . "}\n");
        $long = $this->findingsFor($this->analyzerFor($ext)->analyzePath($f), 'long-function');
        $this->assertCount(1, $long);
    }

    /** @dataProvider extensionProvider */
    public function testCleanCodeHasNoFalsePositive(string $ext): void
    {
        $f = $this->tempFile($ext, "function f() {\n  return 1;\n}\n");
        $dead = $this->findingsFor($this->analyzerFor($ext)->analyzePath($f), 'dead-code-after-return');
        $this->assertCount(0, $dead);
    }
}
