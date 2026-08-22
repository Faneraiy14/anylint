<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class ZigProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Zig';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.zig');
    }

    protected function treeSitterLang(): string
    {
        return 'zig';
    }
}
