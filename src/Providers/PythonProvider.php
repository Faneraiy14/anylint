<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class PythonProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Python';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.py');
    }

    protected function treeSitterLang(): string
    {
        return 'python';
    }
}
