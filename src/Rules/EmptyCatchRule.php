<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Rules\Support\GenuineEmptinessCheck;
use AnyLint\Severity;

/**
 * Так само крос-мовне, як DeadCodeAfterReturnRule: лише структура.
 * AST-порожній catch, що містить ЛИШЕ коментар (напр. "// очікувана
 * помилка, ігноруємо навмисно") - НЕ знахідка, див. GenuineEmptinessCheck.
 */
final class EmptyCatchRule implements Rule
{
    use GenuineEmptinessCheck;

    public function name(): string
    {
        return 'empty-catch';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        foreach ($root->findAll('CatchClause') as $catch) {
            $body = $catch->children[0] ?? null;
            if ($body !== null && $body->children === [] && $this->isGenuinelyEmpty($catch, $source)) {
                $findings[] = new Finding(
                    $filePath,
                    $catch->line,
                    $this->name(),
                    Severity::Warning,
                    'Порожній catch — помилка проковтується мовчки.',
                );
            }
        }
        return $findings;
    }
}
