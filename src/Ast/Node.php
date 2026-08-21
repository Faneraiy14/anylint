<?php

declare(strict_types=1);

namespace AnyLint\Ast;

/**
 * Універсальний "місток"-вузол AST: кожна мова-провайдер перетворює своє
 * рідне дерево розбору в оці однакові вузли (Root/Block/FunctionDecl/
 * Return/TryCatch/CatchClause/...), тож структурні правила (наприклад
 * DeadCodeAfterReturnRule) працюють ОДНАКОВО незалежно від мови джерела -
 * вони ніколи не бачать nikic/php-parser чи будь-що специфічне для PHP.
 *
 * $native - оригінальний вузол рідного парсера мови (напр.
 * PhpParser\Node), доданий НАВМИСНО як "люк" для мовно-специфічних правил
 * (як UnusedVariableRule), яким бракує деталей, що їх канонічна форма
 * свідомо не несе (типи виразів різняться занадто сильно між мовами, щоб
 * їх узагальнювати без втрати сенсу).
 */
final class Node
{
    /**
     * @param array<string,mixed> $attributes
     * @param Node[] $children
     */
    public function __construct(
        public readonly string $type,
        public readonly int $line,
        public readonly array $attributes = [],
        public readonly array $children = [],
        public readonly mixed $native = null,
    ) {
    }

    /** @return Node[] */
    public function findAll(string $type): array
    {
        $found = [];
        if ($this->type === $type) {
            $found[] = $this;
        }
        foreach ($this->children as $child) {
            $found = [...$found, ...$child->findAll($type)];
        }
        return $found;
    }
}
