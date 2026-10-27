<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Metrics\Complexity;
use AnyLint\Config;
use AnyLint\LanguageProvider;
use AnyLint\Providers\NyxilumProvider;
use AnyLint\Providers\PhpProvider;

/**
 * Метрики етапу 3: Complexity, правила complex-function і duplicate-code
 * (ProjectRule - знахідки між файлами) і канонічна форма ланцюжка
 * "if / else if / else" в усіх провайдерах, на якій тримаються і метрики,
 * і deep-nesting.
 */
final class MetricsTest extends AnalyzerTestCase
{
    private const FIXTURES = __DIR__ . '/fixtures/else-if-chain';

    /** @return iterable<string, array{string}> */
    public static function elseIfChainProvider(): iterable
    {
        foreach (glob(self::FIXTURES . '/c.*') ?: [] as $file) {
            yield pathinfo($file, PATHINFO_EXTENSION) => [$file];
        }
    }

    /**
     * Той самий ланцюжок з чотирьох гілок у кожній мові: 3 розгалуження
     * (if + 2 else-if; else - не розгалуження), без жодної вкладеності.
     * Ловить провайдер, що віддає ланцюжок у не-канонічній формі (пласкі
     * elseif-и, else_clause-обгортки тощо).
     *
     * @dataProvider elseIfChainProvider
     */
    public function testElseIfChainIsCanonicalInEveryLanguage(string $file): void
    {
        $provider = $this->providerFor($file);
        $functions = Complexity::functions($provider->parse($file));

        $this->assertCount(1, $functions);
        $this->assertSame(4, $functions[0]->cyclomatic, 'cyclomatic');
        $this->assertSame(3, $functions[0]->cognitive, 'cognitive: ланцюжок - не вкладеність');
    }

    public function testCognitiveComplexityWeighsNesting(): void
    {
        $file = $this->tempPhpFile(<<<'PHP'
            function f($xs) {
                foreach ($xs as $x) {          // +1
                    if ($x) {                  // +2 (вкладеність 1)
                        while ($x--) {         // +3 (вкладеність 2)
                        }
                    }
                }
                try {
                } catch (\Throwable $e) {      // +1
                }
                $g = function () {             // окрема функція, не додається
                    if (true) {
                    }
                };
            }
            PHP);
        $functions = Complexity::functions((new PhpProvider())->parse($file));

        $byName = [];
        foreach ($functions as $metrics) {
            $byName[$metrics->name] = $metrics;
        }
        $this->assertSame(5, $byName['f']->cyclomatic);
        $this->assertSame(7, $byName['f']->cognitive);
    }

    public function testLinesOfCodeSkipsBlankLinesAndBinaryFiles(): void
    {
        $this->assertSame(2, Complexity::linesOfCode("a\n\n   \n\tb\n"));
        $this->assertSame(0, Complexity::linesOfCode("PNG\0\0data"));
    }

    public function testComplexFunctionIsReported(): void
    {
        $body = str_repeat("if (\$a) { if (\$b) { g(); } }\n", 6);
        $file = $this->tempPhpFile("function tangled(\$a, \$b) {\n{$body}}\n");

        $findings = $this->findingsFor($this->newAnalyzer()->analyzePath($file), 'complex-function');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'tangled'", $findings[0]->message);
        $this->assertStringContainsString('складність 18', $findings[0]->message);
    }

    public function testDuplicateAcrossFilesIsReportedInBothFiles(): void
    {
        $dir = $this->tempDir();
        $shared = '';
        for ($i = 1; $i <= 8; $i++) {
            $shared .= "    \$total{$i} = compute(\$input, {$i});\n";
        }
        file_put_contents("{$dir}/a.php", "<?php\nfunction a(\$input) {\n{$shared}}\n");
        file_put_contents("{$dir}/b.php", "<?php\nfunction b(\$input) {\n    prepare();\n{$shared}}\n");

        $findings = $this->findingsFor($this->newAnalyzer()->analyzePath($dir), 'duplicate-code');

        $this->assertCount(2, $findings);
        $this->assertSame([3, 4], array_map(static fn ($f) => $f->line, $findings));
        $this->assertStringContainsString('b.php:4-11', $findings[0]->message);
        $this->assertStringContainsString('a.php:3-10', $findings[1]->message);
    }

    public function testShortRepeatsAndImportBlocksAreNotDuplicates(): void
    {
        $dir = $this->tempDir();
        $imports = '';
        for ($i = 1; $i <= 10; $i++) {
            $imports .= "use Vendor\\Package\\Class{$i};\n";
        }
        file_put_contents("{$dir}/a.php", "<?php\n{$imports}x();\ny();\nz();\n");
        file_put_contents("{$dir}/b.php", "<?php\n{$imports}x();\ny();\nz();\n");

        $this->assertSame([], $this->findingsFor($this->newAnalyzer()->analyzePath($dir), 'duplicate-code'));
    }

    public function testDuplicateCodeHonoursSuppressionsAndMinLines(): void
    {
        $dir = $this->tempDir();
        $shared = '';
        for ($i = 1; $i <= 4; $i++) {
            $shared .= "\$total{$i} = compute(\$input, {$i});\n";
        }
        file_put_contents("{$dir}/a.php", "<?php\n// anylint-disable-file duplicate-code\n{$shared}");
        file_put_contents("{$dir}/b.php", "<?php\n{$shared}");
        file_put_contents("{$dir}/anylint.json", json_encode(['rules' => ['duplicate-code' => ['minLines' => 4]]]));

        $analyzer = $this->newAnalyzer()->withConfig(Config::fromFile("{$dir}/anylint.json"));
        $findings = $this->findingsFor($analyzer->analyzePath($dir), 'duplicate-code');

        $this->assertCount(1, $findings);
        $this->assertStringEndsWith('b.php', $findings[0]->file);
    }

    private function providerFor(string $file): LanguageProvider
    {
        $nodeExe = getenv('NODE_EXE') ?: 'node';
        $candidates = [new PhpProvider()];
        foreach (glob(__DIR__ . '/../src/Providers/*Provider.php') ?: [] as $path) {
            $class = 'AnyLint\\Providers\\' . basename($path, '.php');
            if ($class === PhpProvider::class || $class === NyxilumProvider::class || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $candidates[] = new $class($nodeExe);
        }
        $candidates[] = new NyxilumProvider(getenv('NX_EXE') ?: 'nx');

        foreach ($candidates as $provider) {
            if ($provider->supports($file)) {
                try {
                    $provider->parse($file);
                } catch (\RuntimeException $e) {
                    // Як в інших тестах провайдерів: без node/npm install чи
                    // nx (CI без сестринського репозиторію) - skip, не провал.
                    if (preg_match('/PATH|npm install|не вдалось запустити/u', $e->getMessage()) === 1) {
                        $this->markTestSkipped($e->getMessage());
                    }
                    throw $e;
                }
                return $provider;
            }
        }
        $this->fail("Нема провайдера для {$file}");
    }
}
