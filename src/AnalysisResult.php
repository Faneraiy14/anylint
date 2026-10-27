<?php

declare(strict_types=1);

namespace AnyLint;

/** Результат прогону: по файлу, плюс усі знахідки разом (зокрема від ProjectRule). */
final class AnalysisResult
{
    /**
     * @param list<FileResult> $files у відсортованому порядку шляхів
     * @param Finding[] $findings
     */
    public function __construct(
        public readonly array $files,
        public readonly array $findings,
    ) {
    }
}
