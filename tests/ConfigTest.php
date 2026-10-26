<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Config;
use AnyLint\Severity;

final class ConfigTest extends AnalyzerTestCase
{
    /** @param array<mixed> $data */
    private function writeConfig(string $dir, array $data, string $name = 'anylint.json'): string
    {
        $path = $dir . '/' . $name;
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        return $path;
    }

    private function deepNestingSource(int $depth): string
    {
        return 'function f($x) {' . str_repeat('if ($x) {', $depth) . 'g();' . str_repeat('}', $depth) . "}\n";
    }

    public function testOffDisablesARule(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: x\n");
        $config = Config::fromFile($this->writeConfig($dir, ['rules' => ['todo-tracker' => 'off']]));

        $this->assertSame([], $this->newAnalyzer()->withConfig($config)->analyzePath($dir));
    }

    public function testSeverityCanBeOverridden(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: x\n");
        $config = Config::fromFile($this->writeConfig($dir, ['rules' => ['todo-tracker' => 'error']]));

        $findings = $this->newAnalyzer()->withConfig($config)->analyzePath($dir);
        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Error, $findings[0]->severity);
    }

    public function testThresholdOptionChangesDeepNesting(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n" . $this->deepNestingSource(3));

        $this->assertSame([], $this->findingsFor($this->newAnalyzer()->analyzePath($dir), 'deep-nesting'));

        $config = Config::fromFile($this->writeConfig($dir, ['rules' => ['deep-nesting' => ['maxDepth' => 2]]]));
        $findings = $this->findingsFor($this->newAnalyzer()->withConfig($config)->analyzePath($dir), 'deep-nesting');
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('більше 2', $findings[0]->message);
    }

    public function testLongFunctionThresholdIsConfigurable(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\nfunction f() {" . str_repeat('g();', 5) . "}\n");
        $config = Config::fromFile($this->writeConfig($dir, ['rules' => ['long-function' => ['maxStatements' => 3]]]));

        $this->assertCount(1, $this->findingsFor($this->newAnalyzer()->withConfig($config)->analyzePath($dir), 'long-function'));
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'unknown rule' => [['rules' => ['emty-catch' => 'off']], "невідоме правило 'emty-catch'"];
        yield 'bad severity' => [['rules' => ['empty-catch' => 'fatal']], "'fatal'"];
        yield 'unknown option' => [['rules' => ['deep-nesting' => ['depth' => 2]]], "невідома опція 'depth'"];
        yield 'bad option value' => [['rules' => ['deep-nesting' => ['maxDepth' => 'two']]], 'maxDepth'];
        yield 'options on plain rule' => [['rules' => ['empty-catch' => ['x' => 1]]], 'не має опцій'];
        yield 'unknown top-level key' => [['rule' => []], "невідомий ключ 'rule'"];
        yield 'exclude not a list' => [['exclude' => 'vendor'], 'exclude'];
    }

    /**
     * @param array<mixed> $data
     * @dataProvider invalidConfigs
     */
    public function testInvalidConfigIsAnErrorNotSilentlyIgnored(array $data, string $expected): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($expected);
        $config = Config::fromFile($this->writeConfig($dir, $data));
        $this->newAnalyzer()->withConfig($config)->analyzePath($dir);
    }

    public function testInvalidJsonIsReported(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/anylint.json", '{"rules": ');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('невалідний JSON');
        Config::fromFile("{$dir}/anylint.json");
    }

    public function testExcludePatterns(): void
    {
        $dir = $this->tempDir();
        foreach (['generated/x.php', 'src/keep.php', 'src/deep/a.gen.php', 'src/b.gen.php', 'legacy/old/y.php', 'legacy/new/z.php'] as $rel) {
            @mkdir(dirname("{$dir}/{$rel}"), 0777, true);
            file_put_contents("{$dir}/{$rel}", "<?php\n// TODO: {$rel}\n");
        }
        $config = Config::fromFile($this->writeConfig($dir, [
            'exclude' => ['generated', 'src/**/*.gen.php', './legacy/old/'],
        ]));

        $files = array_map(
            static fn ($f) => substr($f->file, strlen($dir) + 1),
            $this->newAnalyzer()->withConfig($config)->analyzePath($dir),
        );
        sort($files);
        $this->assertSame(['legacy/new/z.php', 'src/keep.php'], $files);
    }

    public function testExplicitlyNamedExcludedFileIsStillSkipped(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/generated");
        file_put_contents("{$dir}/generated/x.php", "<?php\n// TODO: x\n");
        $config = Config::fromFile($this->writeConfig($dir, ['exclude' => ['generated']]));

        $this->assertSame([], $this->newAnalyzer()->withConfig($config)->analyzePath("{$dir}/generated/x.php"));
    }

    public function testDiscoverWalksUpFromTheAnalyzedPath(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/a/b", 0777, true);
        $this->writeConfig($dir, ['rules' => ['todo-tracker' => 'off']], '.anylint.json');

        $config = Config::discover("{$dir}/a/b");
        $this->assertNotNull($config);
        $this->assertTrue($config->isDisabled('todo-tracker'));
        $this->assertSame(realpath($dir), $config->baseDir);
    }
}
