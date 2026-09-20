<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Analyzer;
use AnyLint\Providers\NyxilumProvider;
use AnyLint\Rules\DeadCodeAfterReturnRule;
use AnyLint\Rules\EmptyCatchRule;
use AnyLint\Rules\TodoTrackerRule;

/**
 * Доказ крос-мовності: ті самі структурні правила ловлять ті самі класи
 * багів у .nx, що й у .php, без жодної зміни коду правил. Пропускається,
 * якщо "nx" недоступний (напр. на CI, де сестринський репозиторій
 * NyxilumLang не зібраний) - не провал, а свідомий skip, як GUI-тести
 * пропускаються в самому NyxilumLang.
 */
final class NyxilumProviderTest extends AnalyzerTestCase
{
    private static ?string $nxExe = null;

    public static function setUpBeforeClass(): void
    {
        $nxExe = getenv('NX_EXE') ?: 'nx';
        // proc_open() повертає false (не resource), якщо виконуваний файл
        // не знайдено - перевіряємо is_resource() ЯВНО.
        $process = @proc_open([$nxExe, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $available = is_resource($process) && proc_close($process) === 0;
        if (isset($pipes)) {
            foreach ($pipes as $p) {
                is_resource($p) && fclose($p);
            }
        }
        self::$nxExe = $available ? $nxExe : null;
    }

    private function analyzer(): Analyzer
    {
        if (self::$nxExe === null) {
            $this->markTestSkipped('nx недоступний (NX_EXE не задано чи не в PATH)');
        }
        return (new Analyzer())
            ->withProvider(new NyxilumProvider(self::$nxExe))
            ->withRule(new DeadCodeAfterReturnRule())
            ->withRule(new EmptyCatchRule())
            ->withRule(new TodoTrackerRule());
    }

    public function testDeadCodeAfterReturnCatchesNx(): void
    {
        $f = $this->tempFile('nx', "func f() {\n    return 1\n    print(\"мертвий код\")\n}\n");
        $dead = $this->findingsFor($this->analyzer()->analyzePath($f), 'dead-code-after-return');
        $this->assertCount(1, $dead);
    }

    public function testEmptyCatchCatchesNx(): void
    {
        $f = $this->tempFile('nx', "func f() {\n    try {\n        g()\n    } catch (e) {\n    }\n}\n");
        $empty = $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-catch');
        $this->assertCount(1, $empty);
    }

    public function testTodoTrackerCatchesNxViaTheSameTextEngine(): void
    {
        $f = $this->tempFile('nx', "// TODO: додати перевірку\nfunc f() {}\n");
        $todos = $this->findingsFor($this->analyzer()->analyzePath($f), 'todo-tracker');
        $this->assertCount(1, $todos);
    }

    public function testCleanNxCodeHasNoFalsePositive(): void
    {
        $f = $this->tempFile('nx', "func f() {\n    return 1\n}\n");
        $dead = $this->findingsFor($this->analyzer()->analyzePath($f), 'dead-code-after-return');
        $this->assertCount(0, $dead);
    }
}
