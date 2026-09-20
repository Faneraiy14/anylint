<?php

declare(strict_types=1);

namespace Tests;

final class PhpRulesTest extends AnalyzerTestCase
{
    // --- DeadCodeAfterReturnRule ---

    public function testDeadCodeAfterReturnIsFound(): void
    {
        $f = $this->tempPhpFile('function f() { return 1; echo "мертвий код"; }');
        $dead = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'dead-code-after-return');
        $this->assertCount(1, $dead);
    }

    public function testNoFindingWhenReturnIsLast(): void
    {
        $f = $this->tempPhpFile('function f() { echo "ок"; return 1; }');
        $dead = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'dead-code-after-return');
        $this->assertCount(0, $dead);
    }

    // --- EmptyCatchRule ---

    public function testEmptyCatchIsFound(): void
    {
        $f = $this->tempPhpFile('function f() { try { g(); } catch (\Exception $e) { } }');
        $empty = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-catch');
        $this->assertCount(1, $empty);
    }

    public function testNonEmptyCatchIsNotFlagged(): void
    {
        $f = $this->tempPhpFile('function f() { try { g(); } catch (\Exception $e) { log($e); } }');
        $empty = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-catch');
        $this->assertCount(0, $empty);
    }

    public function testCatchWithOnlyACommentIsNotFlagged(): void
    {
        $f = $this->tempPhpFile("function f() { try { g(); } catch (\Exception \$e) {\n    // навмисно ігноруємо, помилка тут очікувана\n} }");
        $empty = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-catch');
        $this->assertCount(0, $empty);
    }

    // --- DeepNestingRule ---

    public function testFiveLevelsOfNestingIsFound(): void
    {
        $f = $this->tempPhpFile('function f() { if (true) { if (true) { if (true) { if (true) { if (true) { echo "глибоко"; } } } } } }');
        $deep = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'deep-nesting');
        $this->assertCount(1, $deep);
    }

    public function testFourLevelsOfNestingIsNotFlagged(): void
    {
        $f = $this->tempPhpFile('function f() { if (true) { if (true) { if (true) { if (true) { echo "ще ок"; } } } } }');
        $deep = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'deep-nesting');
        $this->assertCount(0, $deep);
    }

    public function testCatchClauseDoesNotAddAnExtraNestingLevelOnTopOfTryCatch(): void
    {
        $f = $this->tempPhpFile('function f() { if (true) { if (true) { if (true) { try { g(); } catch (\Exception $e) { echo "catch не рахується окремо"; } } } } }');
        $deep = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'deep-nesting');
        $this->assertCount(0, $deep);
    }

    // --- EmptyFunctionRule ---

    public function testEmptyFunctionIsFound(): void
    {
        $f = $this->tempPhpFile('function f() { }');
        $empty = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-function');
        $this->assertCount(1, $empty);
    }

    public function testNonEmptyFunctionIsNotFlagged(): void
    {
        $f = $this->tempPhpFile('function f() { echo "не порожня"; }');
        $empty = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-function');
        $this->assertCount(0, $empty);
    }

    public function testFunctionWithOnlyACommentIsNotFlagged(): void
    {
        $f = $this->tempPhpFile("function f() {\n    // TODO: реалізувати пізніше, навмисна заглушка\n}");
        $empty = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-function');
        $this->assertCount(0, $empty);
    }

    public function testInterfaceMethodWithoutABodyIsValidNotAFinding(): void
    {
        $f = $this->tempPhpFile('interface I { function f(); }');
        $empty = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-function');
        $this->assertCount(0, $empty);
    }

    public function testAnEmptyAnonymousClosureArgumentIsNotFlagged(): void
    {
        $f = $this->tempPhpFile('function f() { $cb = function() {}; return $cb; }');
        $empty = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-function');
        $this->assertCount(0, $empty);
    }

    public function testAnEmptyConstructorIsNotFlagged(): void
    {
        $f = $this->tempPhpFile('class C { function __construct() {} }');
        $empty = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-function');
        $this->assertCount(0, $empty);
    }

    // --- LongFunctionRule ---

    public function testThirtyOneStatementsIsFound(): void
    {
        $f = $this->tempPhpFile('function f() { ' . str_repeat('echo 1;', 31) . ' }');
        $long = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'long-function');
        $this->assertCount(1, $long);
    }

    public function testThirtyStatementsAtTheThresholdIsNotFlagged(): void
    {
        $f = $this->tempPhpFile('function f() { ' . str_repeat('echo 1;', 30) . ' }');
        $long = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'long-function');
        $this->assertCount(0, $long);
    }

    // --- EmptyBlockRule ---

    public function testEmptyIfIsFound(): void
    {
        $f = $this->tempPhpFile('function f() { if (true) { } }');
        $eb = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(1, $eb);
    }

    public function testEmptyForIsFound(): void
    {
        $f = $this->tempPhpFile('function f() { for ($i = 0; $i < 10; $i++) { } }');
        $eb = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(1, $eb);
    }

    public function testEmptyWhileIsFound(): void
    {
        $f = $this->tempPhpFile('function f() { while (true) { } }');
        $eb = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(1, $eb);
    }

    public function testEmptyDoWhileIsFound(): void
    {
        $f = $this->tempPhpFile('function f() { do { } while (true); }');
        $eb = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(1, $eb);
    }

    public function testNonEmptyIfIsNotFlagged(): void
    {
        $f = $this->tempPhpFile('function f() { if (true) { echo "не порожньо"; } }');
        $eb = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(0, $eb);
    }

    public function testIfWithOnlyACommentIsNotFlagged(): void
    {
        $f = $this->tempPhpFile("function f() { if (true) {\n    // навмисно нічого не робимо тут\n} }");
        $eb = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-block');
        $this->assertCount(0, $eb);
    }

    // --- UnusedVariableRule ---

    public function testUnusedVariableIsFound(): void
    {
        $f = $this->tempPhpFile('function f() { $unused = 1; return 2; }');
        $unused = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'unused-variable');
        $this->assertCount(1, $unused);
    }

    public function testUsedVariableIsNotFlagged(): void
    {
        $f = $this->tempPhpFile('function f() { $used = 1; return $used; }');
        $unused = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'unused-variable');
        $this->assertCount(0, $unused);
    }

    public function testAssigningASuperglobalIsNotFlaggedAsUnused(): void
    {
        // Реальна хибна знахідка з OpenSourceBikeShare: присвоєння
        // суперглобалі ($_ENV = ...; для скидання оточення в tearDown
        // тестів) синтаксично виглядає як "одне присвоєння, більше не
        // згадується" - але семантично не забута локальна змінна, а
        // навмисний сторонній ефект.
        $f = $this->tempPhpFile('function f() { $_ENV = ["FOO" => "bar"]; }');
        $unused = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'unused-variable');
        $this->assertCount(0, $unused);
    }

    public function testAVariableReadThroughCompactIsNotFlaggedAsUnused(): void
    {
        // compact('connector', ...) читає $connector за рядковим іменем,
        // невидимо для підрахунку Expr\Variable.
        $f = $this->tempPhpFile('function f() { $connector = "x"; return compact("connector"); }');
        $unused = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'unused-variable');
        $this->assertCount(0, $unused);
    }

    // --- Структурні правила бачать середину методів класу ---
    // Регресія на реальний баг: PhpProvider не мав жодної гілки для
    // Stmt\ClassLike (class/interface/trait/enum) - клас провалювався в
    // непрозорий 'Other', і жодне структурне правило ніколи не бачило
    // коду всередині методів.

    public function testUnusedVariableSeesInsideAClassMethodBody(): void
    {
        $f = $this->tempPhpFile('class C { function f() { $unused = 1; return 2; } }');
        $unused = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'unused-variable');
        $this->assertCount(1, $unused);
    }

    public function testDeadCodeAfterReturnSeesInsideAClassMethodBody(): void
    {
        $f = $this->tempPhpFile('class C { function f() { return 1; echo "мертвий код у методі"; } }');
        $dead = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'dead-code-after-return');
        $this->assertCount(1, $dead);
    }

    // --- Структурні правила бачать середину замикань ---
    // Раніше замикання-аргумент/присвоєння як окремий стейтмент не
    // потрапляв у жодну match-гілку PhpProvider::convertStmt, лишався
    // непрозорим "Other" без рекурсії всередину.

    public function testDeadCodeInAClosureArgumentIsFound(): void
    {
        $f = $this->tempPhpFile('array_map(function ($x) { return $x; echo "мертвий"; }, $a);');
        $dead = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'dead-code-after-return');
        $this->assertCount(1, $dead);
    }

    public function testEmptyCatchInAnAssignedClosureIsFound(): void
    {
        $f = $this->tempPhpFile('$fn = function () { try { g(); } catch (\Exception $e) { } };');
        $empty = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-catch');
        $this->assertCount(1, $empty);
    }

    // --- PromotableReturnTypeRule ---

    public function testASimpleReturnDocTagWithoutANativeTypeIsFound(): void
    {
        $f = $this->tempPhpFile("class C {\n/**\n * @return array|null\n */\nfunction f() { return null; }\n}");
        $promo = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'promotable-return-type');
        $this->assertCount(1, $promo);
        $this->assertSame(': array|null', $promo[0]->fix['replacement']);
    }

    public function testAGenericArrayReturnTypeIsNotOfferedForAutoPromotion(): void
    {
        $f = $this->tempPhpFile("class C {\n/**\n * @return array<string, int>\n */\nfunction f() { return []; }\n}");
        $promo = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'promotable-return-type');
        $this->assertCount(0, $promo);
    }

    public function testAnAlreadyNativelyTypedMethodIsNotFlagged(): void
    {
        $f = $this->tempPhpFile("class C {\n/**\n * @return int\n */\nfunction f(): int { return 1; }\n}");
        $promo = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'promotable-return-type');
        $this->assertCount(0, $promo);
    }

    public function testNestedParenthesesInADefaultParameterValueDoNotThrowOffTheFixOffset(): void
    {
        $f = $this->tempPhpFile("class C {\n/**\n * @return string\n */\nfunction f(\$x = (1 + 2)) { return \"hi\"; }\n}");
        $findings = $this->newAnalyzer()->analyzePath($f);
        $promo = $this->findingsFor($findings, 'promotable-return-type');
        $this->assertCount(1, $promo);

        $offset = $promo[0]->fix['startOffset'];
        $original = (string) file_get_contents($f);
        $fixed = substr($original, 0, $offset) . $promo[0]->fix['replacement'] . substr($original, $offset);
        file_put_contents($f, $fixed);
        exec('php -l ' . escapeshellarg($f) . ' 2>&1', $lintOut, $lintCode);
        $this->assertSame(0, $lintCode, 'the applied fix should still be valid PHP');
    }

    // --- TodoTrackerRule - лише всередині коментарів ---

    public function testTodoInALineCommentIsFound(): void
    {
        $f = $this->tempPhpFile("// TODO: зробити пізніше\nfunction f() {}");
        $todos = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'todo-tracker');
        $this->assertCount(1, $todos);
    }

    public function testTheWordTodoInsideAStringLiteralIsNotCaught(): void
    {
        $f = $this->tempPhpFile('$todoList = "не todo-коментар, а рядковий літерал";');
        $todos = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'todo-tracker');
        $this->assertCount(0, $todos);
    }

    public function testHttpUrlWithTodoInTheHostnameIsNotAFalsePositive(): void
    {
        // URL з "TODO" одразу після "//" (частина схеми http://) - раніше
        // плутався зі справжнім коментарем.
        $f = $this->tempPhpFile('$url = "http://TODO.example.com/page";');
        $todos = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'todo-tracker');
        $this->assertCount(0, $todos);
    }

    public function testHttpsUrlWithTodoInTheHostnameIsNotAFalsePositive(): void
    {
        $f = $this->tempPhpFile('$url = "https://TODO.example.com/page";');
        $todos = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'todo-tracker');
        $this->assertCount(0, $todos);
    }

    public function testTodoInALuaDashCommentIsFound(): void
    {
        // TodoTrackerRule (текстове, "працює на будь-якому файлі") раніше
        // не розпізнавав -- як коментар (Lua/SQL/Haskell-стиль).
        $f = $this->tempFile('lua', "-- TODO: реалізувати валідацію\nlocal function f() end\n");
        $todos = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'todo-tracker');
        $this->assertCount(1, $todos);
    }

    // --- Синтаксична помилка / непридатний для читання файл ---

    public function testASyntaxErrorProducesAFindingNotACrash(): void
    {
        $f = $this->tempPhpFile('function f( {{{ зламаний синтаксис');
        $errors = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'parse-error');
        $this->assertCount(1, $errors);
    }

    public function testAnUnreadableFileProducesAFindingNotASilentOkTrue(): void
    {
        // Раніше file_get_contents() повертав false, Analyzer тихо
        // повертав [] - permission-denied файл рахувався "чистим".
        $f = $this->tempPhpFile('function f() { return 1; }');
        chmod($f, 0000);
        $unreadable = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'unreadable-file');
        chmod($f, 0644);
        $this->assertCount(1, $unreadable);
    }
}
