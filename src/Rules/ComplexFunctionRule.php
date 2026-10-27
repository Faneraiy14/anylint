<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\ConfigurableRule;
use AnyLint\Finding;
use AnyLint\Metrics\Complexity;
use AnyLint\Severity;

/**
 * Структурне правило поверх Metrics\Complexity: функція, чия когнітивна
 * складність (розгалуження, зважені вкладеністю) перевищує поріг. Поріг
 * 15 - типове значення Sonar для тієї ж ідеї; наша метрика не рахує
 * && / || / case (див. Complexity), тож на практиці вона трохи нижча за
 * Sonar-івську для того самого коду.
 */
final class ComplexFunctionRule implements ConfigurableRule
{
    private const DEFAULT_MAX_COGNITIVE = 15;

    public function __construct(private readonly int $maxCognitive = self::DEFAULT_MAX_COGNITIVE)
    {
    }

    public function withOptions(array $options): static
    {
        $value = $this->maxCognitive;
        foreach ($options as $name => $option) {
            if ($name !== 'maxCognitive') {
                throw new \InvalidArgumentException("невідома опція '{$name}' (є лише maxCognitive).");
            }
            if (!is_int($option) || $option < 1) {
                throw new \InvalidArgumentException('maxCognitive має бути цілим числом >= 1.');
            }
            $value = $option;
        }
        return new static($value);
    }

    public function name(): string
    {
        return 'complex-function';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        foreach (Complexity::functions($root) as $function) {
            if ($function->cognitive <= $this->maxCognitive) {
                continue;
            }
            $findings[] = new Finding(
                $filePath,
                $function->line,
                $this->name(),
                Severity::Warning,
                sprintf(
                    "Функція '%s' - когнітивна складність %d (більше %d), цикломатична %d - багато вкладених розгалужень, важко читати й тестувати.",
                    $function->name,
                    $function->cognitive,
                    $this->maxCognitive,
                    $function->cyclomatic,
                ),
            );
        }
        return $findings;
    }
}
