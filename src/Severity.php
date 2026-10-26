<?php

declare(strict_types=1);

namespace AnyLint;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
