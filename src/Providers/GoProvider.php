<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class GoProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Go';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.go');
    }

    protected function treeSitterLang(): string
    {
        return 'go';
    }
}
