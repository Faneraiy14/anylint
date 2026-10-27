<?php

declare(strict_types=1);

namespace AnyLint\Metrics;

/**
 * Метрики однієї функції з канонічного дерева - однакові для всіх мов,
 * чий провайдер видає FunctionDecl.
 */
final class FunctionMetrics
{
    public function __construct(
        public readonly string $name,
        public readonly int $line,
        /** 1 + кількість розгалужень (If/For/Foreach/While/Do/CatchClause). */
        public readonly int $cyclomatic,
        /** Розгалуження, зважені глибиною вкладеності (див. Complexity). */
        public readonly int $cognitive,
        /** Стейтменти на верхньому рівні тіла (як у long-function). */
        public readonly int $statements,
    ) {
    }
}
