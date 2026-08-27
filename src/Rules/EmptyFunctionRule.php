<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Rules\Support\GenuineEmptinessCheck;
use AnyLint\Severity;

/**
 * Структурне правило на канонічному FunctionDecl/Block - на сьогодні
 * FunctionDecl видають лише PhpProvider і провайдери JS/TS-родини
 * (AbstractJsFamilyProvider), а не tree-sitter-провайдери. Ловить лише
 * "дужки є, тіла нема" ($body->children === []) - функцію БЕЗ дужок
 * узагалі (наприклад abstract-метод) навмисно НЕ рахує знахідкою, бо це
 * валідна мовна конструкція, а не забута реалізація.
 */
final class EmptyFunctionRule implements Rule
{
    use GenuineEmptinessCheck;

    public function name(): string
    {
        return 'empty-function';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        foreach ($root->findAll('FunctionDecl') as $func) {
            $body = $this->findBody($func);
            if ($body === null || $body->children !== [] || !$this->isGenuinelyEmpty($func, $source)) {
                continue;
            }

            $name = is_string($func->attributes['name'] ?? null) ? $func->attributes['name'] : '?';
            $findings[] = new Finding(
                $filePath,
                $func->line,
                $this->name(),
                Severity::Warning,
                sprintf("Функція '%s' має порожнє тіло - забута реалізація чи навмисна заглушка?", $name),
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
