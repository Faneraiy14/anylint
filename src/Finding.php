<?php

declare(strict_types=1);

namespace AnyLint;

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
     * @param int|null $endLine останній рядок, якщо знахідка - діапазон
     *      (дубльований фрагмент), а не одне місце.
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $rule,
        public readonly Severity $severity,
        public readonly string $message,
        public readonly ?array $fix = null,
        public readonly ?int $endLine = null,
    ) {
    }

    public function withSeverity(Severity $severity): self
    {
        return new self($this->file, $this->line, $this->rule, $severity, $this->message, $this->fix, $this->endLine);
    }
}
