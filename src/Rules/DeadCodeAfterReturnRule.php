<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Severity;

/**
 * Структурне, повністю крос-мовне правило: дивиться лише на канонічні
 * вузли Block/Return, тому працює для БУДЬ-якого провайдера мови, що
 * коректно перетворює свій розбір у ці типи - жодного натяку на PHP чи
 * NyxilumLang тут немає.
 */
final class DeadCodeAfterReturnRule implements Rule
{
    public function name(): string
    {
        return 'dead-code-after-return';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        foreach ($root->findAll('Block') as $block) {
            $returnIndex = null;
            foreach ($block->children as $i => $child) {
                if ($child->type === 'Return') {
                    $returnIndex = $i;
                    break;
                }
            }
            if ($returnIndex === null) {
                continue;
            }
            $after = $block->children[$returnIndex + 1] ?? null;
            if ($after !== null) {
                $findings[] = new Finding(
                    $filePath,
                    $after->line,
                    $this->name(),
                    Severity::Warning,
                    'Код після return ніколи не виконається.',
                );
            }
        }
        return $findings;
    }
}
