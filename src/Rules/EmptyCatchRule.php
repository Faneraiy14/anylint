<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Severity;

/** Так само крос-мовне, як DeadCodeAfterReturnRule: лише структура. */
final class EmptyCatchRule implements Rule
{
    public function name(): string
    {
        return 'empty-catch';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        foreach ($root->findAll('CatchClause') as $catch) {
            $body = $catch->children[0] ?? null;
            if ($body !== null && $body->children === []) {
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
