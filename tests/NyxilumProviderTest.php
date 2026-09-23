<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Analyzer;
use AnyLint\Providers\NyxilumProvider;
use AnyLint\Rules\DeadCodeAfterReturnRule;
use AnyLint\Rules\DeepNestingRule;
use AnyLint\Rules\EmptyBlockRule;
use AnyLint\Rules\EmptyCatchRule;
use AnyLint\Rules\EmptyFunctionRule;
use AnyLint\Rules\LongFunctionRule;
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
            ->withRule(new DeepNestingRule())
            ->withRule(new EmptyBlockRule())
            ->withRule(new EmptyCatchRule())
            ->withRule(new EmptyFunctionRule())
            ->withRule(new LongFunctionRule())
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

    /**
     * Ці чотири правила (deep-nesting/empty-block/empty-function/
     * long-function) НЕ мали жодного .nx-тесту дотепер, хоча канонічний
     * AST-місток (AstJsonDumper.cs, NyxilumLang) уже давно видає все
     * потрібне для них - If/While/FunctionDecl/Block з правильною
     * вкладеністю й лічильником стейтментів, той самий формат, що й
     * PhpProvider. Додано зараз (22.09.2026), щоб реально перевірити
     * живцем, а не просто припустити, що "мала б спрацювати".
     */
    public function testDeepNestingCatchesNx(): void
    {
        $f = $this->tempFile('nx', <<<'NX'
            func f() {
                if (true) {
                    if (true) {
                        if (true) {
                            if (true) {
                                if (true) {
                                    print("занадто глибоко")
                                }
                            }
                        }
                    }
                }
            }
            NX);
        $deep = $this->findingsFor($this->analyzer()->analyzePath($f), 'deep-nesting');
        $this->assertCount(1, $deep);
    }

    public function testEmptyBlockCatchesNx(): void
    {
        $f = $this->tempFile('nx', "func f() {\n    if (true) {\n    }\n}\n");
        $empty = $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(1, $empty);
    }

    public function testEmptyFunctionCatchesNx(): void
    {
        $f = $this->tempFile('nx', "func emptyFn() {\n}\n");
        $empty = $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-function');
        $this->assertCount(1, $empty);
    }

    /**
     * Регрес: struct-методи парсяться в Parser.cs напряму через
     * ParseFunctionDeclaration(), в обхід ParseStatement() (де стоїть
     * єдиний центральний штамп .Line = token.Line) - тож FunctionDecl
     * для КОЖНОГО методу структури видавав line=0 в 'nx ast', а
     * GenuineEmptinessCheck::byteOffsetOfLine() трактував line<1 як
     * "офсет невідомий" -> isGenuinelyEmpty() повертав true БЕЗ спроби
     * прочитати сирий текст - коментар усередині тіла ігнорувався
     * повністю, для будь-якого методу структури. Топрівневі функції не
     * зачіпало (вони йдуть через ParseStatement, лінія проставляється
     * нормально) - тому баг довго лишався непоміченим.
     */
    public function testStructMethodWithOnlyACommentIsNotFlaggedNx(): void
    {
        $f = $this->tempFile('nx', <<<'NX'
            struct SilentScript {
                tag: string
                func update(dt, canvas) {
                    // intentionally empty
                }
            }
            NX);
        $empty = $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-function');
        $this->assertCount(0, $empty);
    }

    /** Той самий баг мав побічний ефект: коли метод СПРАВДІ порожній, знахідка все одно репортувалась з line=0 замість реального рядка методу. */
    public function testEmptyStructMethodReportsItsOwnLineNotZeroNx(): void
    {
        $f = $this->tempFile('nx', <<<'NX'
            struct SilentScript {
                tag: string
                func update(dt, canvas) {
                }
            }
            NX);
        $empty = $this->findingsFor($this->analyzer()->analyzePath($f), 'empty-function');
        $this->assertCount(1, $empty);
        $this->assertSame(3, $empty[0]->line);
    }

    public function testLongFunctionCatchesNx(): void
    {
        $body = implode("\n", array_map(static fn (int $i) => "    print(\"{$i}\")", range(1, 31)));
        $f = $this->tempFile('nx', "func f() {\n{$body}\n}\n");
        $long = $this->findingsFor($this->analyzer()->analyzePath($f), 'long-function');
        $this->assertCount(1, $long);
    }

    public function testShallowShortNonEmptyNxHasNoFalsePositiveOnAnyOfTheFour(): void
    {
        $f = $this->tempFile('nx', "func f() {\n    if (true) {\n        print(\"ок\")\n    }\n}\n");
        $findings = $this->analyzer()->analyzePath($f);
        foreach (['deep-nesting', 'empty-block', 'empty-function', 'long-function'] as $rule) {
            $this->assertCount(0, $this->findingsFor($findings, $rule), "неочікувана знахідка '{$rule}'");
        }
    }
}
