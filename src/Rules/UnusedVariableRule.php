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

    /** @param Stmt[] $stmts */
    private function checkFunctionBody(array $stmts, string $filePath): array
    {
        $finder = new NodeFinder();

        /** @var Expr\Variable[] $allVars */
        $allVars = $finder->find($stmts, fn (PhpNode $n) => $n instanceof Expr\Variable && is_string($n->name));

        $countByName = [];
        foreach ($allVars as $v) {
            $countByName[$v->name] = ($countByName[$v->name] ?? 0) + 1;
        }

        /** @var Expr\Assign[] $assigns */
        $assigns = $finder->find($stmts, fn (PhpNode $n) => $n instanceof Expr\Assign && $n->var instanceof Expr\Variable);

        $findings = [];
        $reported = [];
        foreach ($assigns as $assign) {
            /** @var Expr\Variable $target */
            $target = $assign->var;
            $name = is_string($target->name) ? $target->name : null;
            if ($name === null || isset($reported[$name])) {
                continue;
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
