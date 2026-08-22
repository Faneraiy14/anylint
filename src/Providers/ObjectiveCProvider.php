<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class ObjectiveCProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Objective-C';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.m') || str_ends_with($filePath, '.mm');
    }

    protected function treeSitterLang(): string
    {
        return 'objc';
    }
}
