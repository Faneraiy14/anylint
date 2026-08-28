<?php

declare(strict_types=1);

namespace AnyLint;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}

/**
 * @phpstan-type FixData array{startOffset: int, endOffset: int, replacement: string}
 */
final class Finding
{
    /**
     * @param FixData|null $fix Байтові зсуви в $source (не рядок/стовпець -
     *      однозначні незалежно від кодування, споживач сам рахує рядок/
     *      стовпець за потреби, як-от VS Code-розширення для quick fix).
     *      null, якщо для цієї знахідки немає автоматичного виправлення.
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $rule,
        public readonly Severity $severity,
        public readonly string $message,
        public readonly ?array $fix = null,
    ) {
    }
}
