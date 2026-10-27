<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\ConfigurableRule;
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
final class LongFunctionRule implements ConfigurableRule
{
    private const DEFAULT_MAX_STATEMENTS = 30;

    public function __construct(private readonly int $maxStatements = self::DEFAULT_MAX_STATEMENTS)
    {
    }

    public function withOptions(array $options): static
    {
        $value = $this->maxStatements;
        foreach ($options as $name => $option) {
            if ($name !== 'maxStatements') {
                throw new \InvalidArgumentException("невідома опція '{$name}' (є лише maxStatements).");
            }
            if (!is_int($option) || $option < 1) {
                throw new \InvalidArgumentException('maxStatements має бути цілим числом >= 1.');
            }
            $value = $option;
        }
        return new static($value);
    }

    public function name(): string
    {
        return 'long-function';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        foreach ($root->findAll('FunctionDecl') as $func) {
            $body = $func->body();
            if ($body === null) {
                continue;
            }

            $count = count($body->children);
            if ($count <= $this->maxStatements) {
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
                    $this->maxStatements,
                ),
            );
        }
        return $findings;
    }
}
