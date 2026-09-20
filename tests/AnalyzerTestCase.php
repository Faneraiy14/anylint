<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Analyzer;
use AnyLint\Providers\PhpProvider;
use AnyLint\Rules\DeadCodeAfterReturnRule;
use AnyLint\Rules\DeepNestingRule;
use AnyLint\Rules\EmptyBlockRule;
use AnyLint\Rules\EmptyCatchRule;
use AnyLint\Rules\EmptyFunctionRule;
use AnyLint\Rules\HardcodedSecretRule;
use AnyLint\Rules\LongFunctionRule;
use AnyLint\Rules\PromotableReturnTypeRule;
use AnyLint\Rules\TodoTrackerRule;
use AnyLint\Rules\UnusedVariableRule;
use AnyLint\Rules\WindowsScriptEncodingRule;
use PHPUnit\Framework\TestCase;

abstract class AnalyzerTestCase extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tmpDirs = [];
    }

    protected function tempPhpFile(string $contents): string
    {
        $dir = sys_get_temp_dir() . '/anylint_test_' . uniqid('', true);
        mkdir($dir);
        $this->tmpDirs[] = $dir;
        $path = $dir . '/test.php';
        file_put_contents($path, "<?php\n" . $contents);
        return $path;
    }

    protected function tempFile(string $ext, string $contents): string
    {
        $dir = sys_get_temp_dir() . '/anylint_test_' . uniqid('', true);
        mkdir($dir);
        $this->tmpDirs[] = $dir;
        $path = $dir . '/test.' . $ext;
        file_put_contents($path, $contents);
        return $path;
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/anylint_dir_' . uniqid('', true);
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    protected function newAnalyzer(): Analyzer
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
            ->withRule(new PromotableReturnTypeRule())
            ->withRule(new WindowsScriptEncodingRule())
            ->withRule(new TodoTrackerRule());
    }

    /**
     * @param list<\AnyLint\Finding> $findings
     * @return list<\AnyLint\Finding>
     */
    protected function findingsFor(array $findings, string $rule): array
    {
        return array_values(array_filter($findings, static fn ($x) => $x->rule === $rule));
    }
}
