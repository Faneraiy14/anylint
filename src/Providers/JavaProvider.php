<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class JavaProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Java';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.java');
    }

    protected function treeSitterLang(): string
    {
        return 'java';
    }
}
