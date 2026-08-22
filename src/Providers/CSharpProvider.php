<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class CSharpProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'C#';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.cs');
    }

    protected function treeSitterLang(): string
    {
        return 'c_sharp';
    }
}
