<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Severity;
use PhpParser\Node\Stmt;

/**
 * PHP-специфічне (як UnusedVariableRule): "тепер можна ставити типи не в
 * phpdoc, а прямо в декларацію" - реальний коментар з рев'ю дяді Вови
 * (OpenSourceBikeShare PR #353) про PdoDbResult/DbResultInterface. Це
 * правило знаходить те саме автоматично - метод із `@return TYPE` в
 * докблоку, але БЕЗ нативного return type - і пропонує безпечний
 * автофікс (Finding::$fix), яким скористається VS Code-розширення.
 *
 * Свідомо консервативне, як і UnusedVariableRule: краще пропустити
 * складний випадок, ніж згенерувати невалідний PHP. Тому:
 * - лише прості типи (примітиви/ідентифікатори, опційна "?", union через
 *   "|") - жодних generics-нотацій на кшталт array<string,int> чи
 *   list<Foo> (природно відкидаються regex'ом - там є "<", яких у
 *   простому типі бути не може);
 * - лише Stmt\Function_/Stmt\ClassMethod, НЕ замикання (Expr\Closure) -
 *   там після параметрів може йти ще "use (...)" перед return type,
 *   що ускладнює пошук точки вставки без реальної користі (closures
 *   рідко документують @return у докблоці окремо від сигнатури).
 */
final class PromotableReturnTypeRule implements Rule
{
    // ?Foo\Bar або Foo|Bar|Baz - жодних generics/array-shapes (ті містять
    // "<", "{", пробіли чи дужки, які цей patern не пропустить).
    private const SAFE_TYPE_RE = '/^\??[A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:\|[A-Za-z_\\\\][A-Za-z0-9_\\\\]*)*$/';

    public function name(): string
    {
        return 'promotable-return-type';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        foreach ($root->findAll('FunctionDecl') as $funcNode) {
            $native = $funcNode->native;
            if (!($native instanceof Stmt\Function_) && !($native instanceof Stmt\ClassMethod)) {
                continue;
            }
            if ($native->returnType !== null) {
                continue; // вже нативний тип - нема що промотувати
            }

            $doc = $native->getDocComment();
            if ($doc === null || !preg_match('/@return\s+(\S+)/', $doc->getText(), $m)) {
                continue;
            }
            $type = $m[1];
            if (!preg_match(self::SAFE_TYPE_RE, $type)) {
                continue;
            }

            $insertAt = $this->findParamListEnd($source, $native->getStartFilePos());
            if ($insertAt === null) {
                continue;
            }

            $findings[] = new Finding(
                $filePath,
                $native->getStartLine(),
                $this->name(),
                Severity::Info,
                "Тип \"{$type}\" є лише в @return - можна поставити нативно: \": {$type}\".",
                ['startOffset' => $insertAt, 'endOffset' => $insertAt, 'replacement' => ": {$type}"],
            );
        }
        return $findings;
    }

    /**
     * Байтовий зсув ОДРАЗУ ПІСЛЯ дужки, що закриває список параметрів -
     * рахує глибину дужок від першої "(" після початку вузла, тож
     * дужки всередині значень за замовчуванням параметрів (напр.
     * function f($x = foo(1, 2))) не збивають підрахунок.
     */
    private function findParamListEnd(string $source, int $nodeStart): ?int
    {
        $openPos = strpos($source, '(', $nodeStart);
        if ($openPos === false) {
            return null;
        }

        $depth = 0;
        $len = strlen($source);
        for ($i = $openPos; $i < $len; $i++) {
            if ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i + 1;
                }
            }
        }
        return null;
    }
}
