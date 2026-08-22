<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class KotlinProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Kotlin';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.kt') || str_ends_with($filePath, '.kts');
    }

    protected function treeSitterLang(): string
    {
        return 'kotlin';
    }
}
