<?php

declare(strict_types=1);

namespace AnyLint;

use AnyLint\Metrics\FunctionMetrics;

/**
 * Усе, що аналіз дізнався про один файл: знахідки, мова, метрики і
 * "факти" для правил рівня проєкту (ProjectRule). Саме це кешується й
 * передається з паралельних процесів - тож метрики й факти для
 * дублювання не перераховуються для незмінених файлів.
 */
final class FileResult
{
    /** Класи, які дозволено відновлювати з unserialize() (кеш, --jobs). */
    public const SERIALIZABLE = [self::class, Finding::class, Severity::class, FunctionMetrics::class];

    /**
     * @param Finding[] $findings
     * @param list<FunctionMetrics> $functions
     * @param array<string, mixed> $facts назва ProjectRule => її факти про цей файл
     */
    public function __construct(
        public readonly string $file,
        public readonly array $findings,
        public readonly ?string $language = null,
        public readonly int $linesOfCode = 0,
        public readonly array $functions = [],
        public readonly array $facts = [],
    ) {
    }

    /** @param Finding[] $findings */
    public function withFindings(array $findings): self
    {
        return new self($this->file, $findings, $this->language, $this->linesOfCode, $this->functions, $this->facts);
    }
}
