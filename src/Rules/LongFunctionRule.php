<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Severity;

/**
 * Структурне правило на канонічному FunctionDecl/Block - та сама
 * поточна межа покриття, що й EmptyFunctionRule (PHP + JS/TS-родина,
 * не tree-sitter-провайдери, бо ті не видають FunctionDecl).
 * Рахує лише ПРЯМИХ дітей тіла функції (не рекурсивно вглиб вкладених
 * блоків) - це навмисно, "довга функція" означає багато кроків підряд
 * на одному рівні, а не глибоку вкладеність (те вже ловить
 * DeepNestingRule окремо).
 */
final class LongFunctionRule implements Rule
{
    private const MAX_STATEMENTS = 30;

    public function name(): string
    {
        return 'long-function';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        foreach ($root->findAll('FunctionDecl') as $func) {
            $body = $this->findBody($func);
            if ($body === null) {
                continue;
            }

            $count = count($body->children);
            if ($count <= self::MAX_STATEMENTS) {
                continue;
            }

            $name = is_string($func->attributes['name'] ?? null) ? $func->attributes['name'] : '?';
            $findings[] = new Finding(
                $filePath,
                $func->line,
                $this->name(),
                Severity::Warning,
                sprintf(
                    "Функція '%s' - %d стейтментів на верхньому рівні (більше %d) - варто розбити на менші.",
                    $name,
                    $count,
                    self::MAX_STATEMENTS,
                ),
            );
        }
        return $findings;
    }

    /**
     * FunctionDecl.children не завжди [тіло] - JS/TS-провайдер кладе туди
     * ще й параметри/декоратори/типи (навмисно, щоб не пропустити
     * замикання у дефолтних значеннях параметрів), тож тіло треба шукати
     * за типом Block, а не брати за індексом 0.
     */
    private function findBody(Node $func): ?Node
    {
        foreach ($func->children as $child) {
            if ($child->type === 'Block') {
                return $child;
            }
        }
        return null;
    }
}
