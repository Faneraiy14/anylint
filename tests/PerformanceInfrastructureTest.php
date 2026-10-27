<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Analyzer;
use AnyLint\Ast\Node;
use AnyLint\Config;
use AnyLint\FileResult;
use AnyLint\Finding;
use AnyLint\ParallelRunner;
use AnyLint\Providers\NodeWorker;
use AnyLint\Providers\PhpProvider;
use AnyLint\ResultCache;
use AnyLint\Rule;
use AnyLint\Rules\TodoTrackerRule;
use AnyLint\Severity;

/** Рахує виклики check() - показує, чи файл реально аналізувався, чи прийшов з кешу. */
final class CountingRule implements Rule
{
    public int $calls = 0;

    public function name(): string
    {
        return 'counting';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $this->calls++;
        return [];
    }
}

/**
 * Етап 2: кеш результатів, паралельний аналіз, довгоживучий node-процес.
 */
final class PerformanceInfrastructureTest extends AnalyzerTestCase
{
    private function cachedAnalyzer(string $cacheFile, CountingRule $counter): Analyzer
    {
        return (new Analyzer())
            ->withProvider(new PhpProvider())
            ->withRule(new TodoTrackerRule())
            ->withRule($counter)
            ->withCache($cacheFile);
    }

    public function testUnchangedFilesComeFromCacheAndChangedOnesAreReanalyzed(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: a\n");
        file_put_contents("{$dir}/b.php", "<?php\n// TODO: b\n");
        $cacheFile = $this->tempDir() . '/cache/x.cache';

        $first = new CountingRule();
        $cold = $this->cachedAnalyzer($cacheFile, $first)->analyzePath($dir);
        $this->assertSame(2, $first->calls);
        $this->assertFileExists($cacheFile);

        $second = new CountingRule();
        $warm = $this->cachedAnalyzer($cacheFile, $second)->analyzePath($dir);
        $this->assertSame(0, $second->calls, 'nothing changed - nothing should be re-analyzed');
        $this->assertEquals($cold, $warm);

        file_put_contents("{$dir}/b.php", "<?php\n// TODO: b2\n");
        $third = new CountingRule();
        $after = $this->cachedAnalyzer($cacheFile, $third)->analyzePath($dir);
        $this->assertSame(1, $third->calls);
        $this->assertSame(['TODO: a', 'TODO: b2'], array_map(static fn (Finding $f) => $f->message, $after));
    }

    public function testConfigChangeInvalidatesTheCache(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: a\n");
        $cacheFile = $this->tempDir() . '/x.cache';
        $this->cachedAnalyzer($cacheFile, new CountingRule())->analyzePath($dir);

        $counter = new CountingRule();
        $config = Config::fromArray(['rules' => ['todo-tracker' => 'error']], $dir, 'test');
        $findings = $this->cachedAnalyzer($cacheFile, $counter)->withConfig($config)->analyzePath($dir);

        $this->assertSame(1, $counter->calls);
        $this->assertSame(Severity::Error, $findings[0]->severity);
    }

    public function testParseErrorsAreNotCached(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/bad.php", "<?php\nfunction (\n");
        $cacheFile = $this->tempDir() . '/x.cache';
        $this->cachedAnalyzer($cacheFile, new CountingRule())->analyzePath($dir);

        $counter = new CountingRule();
        $findings = $this->cachedAnalyzer($cacheFile, $counter)->analyzePath($dir);
        $this->assertSame(1, $counter->calls, 'a parse-error may be environmental - must be retried');
        $this->assertCount(1, $this->findingsFor($findings, 'parse-error'));
    }

    public function testCorruptedCacheIsIgnoredNotAnError(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: a\n");
        $cacheFile = $this->tempDir() . '/x.cache';
        file_put_contents($cacheFile, 'garbage');

        $counter = new CountingRule();
        $findings = $this->cachedAnalyzer($cacheFile, $counter)->analyzePath($dir);
        $this->assertSame(1, $counter->calls);
        $this->assertCount(1, $findings);
    }

    public function testDefaultCacheFileIsOutsideTheProject(): void
    {
        $dir = $this->tempDir();
        $file = ResultCache::defaultFile($dir);
        $this->assertStringStartsWith(sys_get_temp_dir(), $file);
        $this->assertStringNotContainsString($dir, $file);
    }

    public function testParallelRunGivesTheSameFindingsInTheSameOrder(): void
    {
        if (!ParallelRunner::isSupported()) {
            $this->markTestSkipped('pcntl недоступний');
        }
        $dir = $this->tempDir();
        for ($i = 0; $i < 12; $i++) {
            file_put_contents("{$dir}/f{$i}.php", "<?php\n// TODO: {$i}\n" . str_repeat("\$x = 1;\n", $i * 50));
        }
        $sequential = $this->newAnalyzer()->analyzePath($dir);
        $parallel = $this->newAnalyzer()->withJobs(3)->analyzePath($dir);

        $this->assertCount(12, $sequential);
        $this->assertEquals($sequential, $parallel);
    }

    public function testAnErrorInsideAWorkerIsReportedNotSwallowed(): void
    {
        if (!ParallelRunner::isSupported()) {
            $this->markTestSkipped('pcntl недоступний');
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom in b');
        ParallelRunner::run(['a', 'b', 'c'], 2, static function (string $file): FileResult {
            if ($file === 'b') {
                throw new \RuntimeException('boom in b');
            }
            return new FileResult($file, []);
        });
    }

    public function testNodeWorkerRestartsAfterTheProcessDies(): void
    {
        $nodeExe = getenv('NODE_EXE') ?: 'node';
        exec(escapeshellarg($nodeExe) . ' --version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('node недоступний');
        }
        // Відповідає на один запит і "падає" - наступний запит має піти в
        // новий, перезапущений процес, а не стати помилкою.
        $script = $this->tempFile('js', <<<'JS'
            const rl = require('readline').createInterface({ input: process.stdin });
            rl.on('line', (line) => {
              process.stdout.write(JSON.stringify({ code: 0, ast: { type: 'Root', line: 1, attributes: {}, children: [] }, echo: line }) + '\n');
              process.exit(1);
            });
            JS);
        $worker = new NodeWorker($nodeExe, $script);

        $this->assertSame(0, $worker->request(['file' => 'one'])['code']);
        $this->assertSame(0, $worker->request(['file' => 'two'])['code']);
    }

    public function testNodeWorkerReportsAMissingScriptClearly(): void
    {
        $nodeExe = getenv('NODE_EXE') ?: 'node';
        exec(escapeshellarg($nodeExe) . ' --version 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('node недоступний');
        }
        $worker = new NodeWorker($nodeExe, '/nonexistent/dump.js');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("npm install");
        $worker->request(['file' => 'x']);
    }
}
