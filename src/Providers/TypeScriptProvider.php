<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class TypeScriptProvider extends AbstractJsFamilyProvider
{
    public function name(): string
    {
        return 'TypeScript';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.ts') || str_ends_with($filePath, '.tsx');
    }
}
