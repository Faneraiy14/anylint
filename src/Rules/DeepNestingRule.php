<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Severity;

/**
 * Структурне, крос-мовне правило: дивиться лише на канонічні
 * If/For/Foreach/While/Do/TryCatch і рахує, скільки їх вкладено одне в
 * одне. CatchClause навмисно не рахується окремо від свого TryCatch - це
 * одна конструкція, а не два рівні вкладеності.
 */
final class DeepNestingRule implements Rule
{
    private const MAX_DEPTH = 4;

    /** @var list<string> */
    private const CONTROL_TYPES = ['If', 'For', 'Foreach', 'While', 'Do', 'TryCatch'];

    public function name(): string
    {
        return 'deep-nesting';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        $this->walk($root, 0, $findings, $filePath);
        return $findings;
    }

    /** @param list<Finding> $findings */
    private function walk(Node $node, int $depth, array &$findings, string $filePath): void
    {
        $isControl = in_array($node->type, self::CONTROL_TYPES, true);
        $nextDepth = $isControl ? $depth + 1 : $depth;

        if ($isControl && $nextDepth > self::MAX_DEPTH) {
            $findings[] = new Finding(
                $filePath,
                $node->line,
                $this->name(),
                Severity::Warning,
                sprintf(
                    'Вкладеність керуючих конструкцій - %d рівнів (більше %d) - важко читати й тестувати.',
                    $nextDepth,
                    self::MAX_DEPTH,
                ),
            );
        }

        foreach ($node->children as $child) {
            // "else if" без окремих фігурних дужок - JS/TS і всі
            // tree-sitter-мови представляють цей ланцюжок як If, вкладений
            // прямо в If (наступна гілка ланцюжка - природний дочірній
            // вузол попередньої, без проміжного Block). Структурно це
            // виглядає як вкладеність, але це той самий плаский ланцюжок
            // умов, не нова глибина - тож рахуємо продовження на тому ж
            // рівні, що й сам If, а не як +1. PHP цього не потребує -
            // PhpProvider уже сплощує elseif у Block-сусідів одного If.
            if ($node->type === 'If' && $child->type === 'If') {
                $this->walk($child, $depth, $findings, $filePath);
                continue;
            }
            $this->walk($child, $nextDepth, $findings, $filePath);
        }
    }
}
