<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class SwiftProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Swift';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.swift');
    }

    protected function treeSitterLang(): string
    {
        return 'swift';
    }
}
