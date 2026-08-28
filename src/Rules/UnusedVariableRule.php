<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Severity;
use PhpParser\Node as PhpNode;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * На відміну від DeadCodeAfterReturnRule/EmptyCatchRule, це правило
 * ЗНАЄ, що працює з PHP: семантика "невикористаної змінної" (область
 * видимості, посилання, deconstructuring тощо) занадто відрізняється між
 * мовами, щоб узагальнювати без втрати сенсу. Читає $node->native
 * (справжній PhpParser\Node, який PhpProvider навмисно зберігає в
 * кожному FunctionDecl) - той самий Rule-інтерфейс, що й у крос-мовних
 * правил, просто ця реалізація свідомо вужча.
 *
 * Евристика: змінна, що присвоюється рівно один раз і НІДЕ БІЛЬШЕ не
 * зустрічається в тілі функції (ні читання, ні повторне присвоєння),
 * позначається як невикористана. Свідомо консервативно - краще пропустити
 * складний випадок, ніж хибно звинуватити змінну, що насправді читається.
 */
final class UnusedVariableRule implements Rule
{
    // Суперглобальні змінні PHP - призначення в них ($_ENV = ...;,
    // $_SESSION = ...;) навмисно про сторонній ефект (значення читає
    // рантайм PHP чи інший код ПОЗА цією функцією), а не про локальний
    // облік у самій функції. Синтаксично вони виглядають ідентично до
    // "звичайної" забутої змінної (одне присвоєння, більше ніде не
    // згадується в тілі) - але семантично це геть інший випадок. Виявлено
    // емпірично на реальному коді (OpenSourceBikeShare): "$_ENV" присвоюється
    // рівно один раз для СКИДАННЯ середовища в tearDown() тестів (нормальний,
    // навмисний патерн) хибно позначалось як "невикористана змінна".
    private const SUPERGLOBALS = [
        '_ENV', '_SERVER', '_GET', '_POST', '_REQUEST', '_SESSION', '_COOKIE', '_FILES', 'GLOBALS',
    ];

    public function name(): string
    {
        return 'unused-variable';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        foreach ($root->findAll('FunctionDecl') as $funcNode) {
            $native = $funcNode->native;
            if (!($native instanceof Stmt\Function_) && !($native instanceof Stmt\ClassMethod)) {
                continue;
            }
            if ($native->stmts === null) {
                continue;
            }
            $findings = [...$findings, ...$this->checkFunctionBody($native->stmts, $filePath)];
        }
        return $findings;
    }

    /**
     * @param Stmt[] $stmts
     * @return list<Finding>
     */
    private function checkFunctionBody(array $stmts, string $filePath): array
    {
        $finder = new NodeFinder();

        /** @var Expr\Variable[] $allVars */
        $allVars = $finder->find($stmts, fn (PhpNode $n) => $n instanceof Expr\Variable && is_string($n->name));

        $countByName = [];
        foreach ($allVars as $v) {
            // $finder-фільтр вище вже перевіряв is_string($n->name), але
            // PHPStan не проносить цю перевірку через замикання в тип
            // елементів $allVars (Expr\Variable::$name задекларовано як
            // Expr|string - для "змінних змінних" $$foo). Тому перевірка
            // тут - не дублювання, а єдине місце, де PHPStan справді бачить
            // звуження до string.
            if (!is_string($v->name)) {
                continue;
            }
            $countByName[$v->name] = ($countByName[$v->name] ?? 0) + 1;
        }

        // compact('foo', 'bar')/extract() читають/пишуть локальні змінні за
        // РЯДКОВИМ іменем - невидимо для підрахунку Expr\Variable вище.
        // Виявлено емпірично на реальному коді (OpenSourceBikeShare):
        // compact('connector', 'exception') хибно позначало $connector як
        // невикористану, хоч вона реально читається через рядкове ім'я.
        // get_defined_vars() свідомо НЕ тут - вона не приймає імена, забирає
        // ВСІ локальні змінні, тож сама її наявність робить "невикористана
        // змінна" непридатною перевіркою для всієї функції одразу.
        $hasGetDefinedVars = $finder->findFirst(
            $stmts,
            fn (PhpNode $n) => $n instanceof Expr\FuncCall
                && $n->name instanceof \PhpParser\Node\Name
                && strtolower($n->name->toString()) === 'get_defined_vars',
        ) !== null;
        if ($hasGetDefinedVars) {
            return [];
        }

        /** @var Expr\FuncCall[] $compactCalls */
        $compactCalls = $finder->find(
            $stmts,
            fn (PhpNode $n) => $n instanceof Expr\FuncCall
                && $n->name instanceof \PhpParser\Node\Name
                && in_array(strtolower($n->name->toString()), ['compact', 'extract'], true),
        );
        $namedByString = [];
        foreach ($compactCalls as $call) {
            foreach ($call->args as $arg) {
                if ($arg instanceof \PhpParser\Node\Arg && $arg->value instanceof \PhpParser\Node\Scalar\String_) {
                    $namedByString[$arg->value->value] = true;
                }
            }
        }

        /** @var Expr\Assign[] $assigns */
        $assigns = $finder->find($stmts, fn (PhpNode $n) => $n instanceof Expr\Assign && $n->var instanceof Expr\Variable);

        $findings = [];
        $reported = [];
        foreach ($assigns as $assign) {
            /** @var Expr\Variable $target */
            $target = $assign->var;
            $name = is_string($target->name) ? $target->name : null;
            if ($name === null || isset($reported[$name]) || in_array($name, self::SUPERGLOBALS, true)) {
                continue;
            }
            if (isset($namedByString[$name])) {
                continue; // читається через compact()/extract() за рядковим іменем
            }
            if (($countByName[$name] ?? 0) === 1) {
                $findings[] = new Finding(
                    $filePath,
                    $assign->getLine(),
                    $this->name(),
                    Severity::Warning,
                    "Змінна \${$name} присвоюється, але ніде не використовується.",
                );
                $reported[$name] = true;
            }
        }
        return $findings;
    }
}
