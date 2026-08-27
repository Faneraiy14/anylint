<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Rules\Support\GenuineEmptinessCheck;
use AnyLint\Severity;

/**
 * Той самий принцип, що й у EmptyCatchRule, лише поширений на решту
 * керуючих конструкцій. Повністю крос-мовне: If/For/Foreach/While/Do -
 * єдині типи вузлів, що є в АБСОЛЮТНО ВСІХ 16 провайдерів (на відміну
 * від FunctionDecl, якого tree-sitter-провайдери ще не видають).
 */
final class EmptyBlockRule implements Rule
{
    use GenuineEmptinessCheck;

    /** @var list<string> */
    private const CONTROL_TYPES = ['If', 'For', 'Foreach', 'While', 'Do'];

    public function name(): string
    {
        return 'empty-block';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        foreach (self::CONTROL_TYPES as $type) {
            foreach ($root->findAll($type) as $node) {
                foreach ($node->children as $block) {
                    if ($block->type !== 'Block' || $block->children !== []) {
                        continue;
                    }
                    if (!$this->isGenuinelyEmpty($node, $source)) {
                        continue;
                    }
                    $findings[] = new Finding(
                        $filePath,
                        $node->line,
                        $this->name(),
                        Severity::Warning,
                        sprintf("Порожнє тіло '%s' - забута логіка чи заглушка?", $type),
                    );
                }
            }
        }
        return $findings;
    }
}
