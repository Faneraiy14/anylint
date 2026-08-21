<?php

declare(strict_types=1);

namespace AnyLint;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}

final class Finding
{
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $rule,
        public readonly Severity $severity,
        public readonly string $message,
    ) {
    }
}
