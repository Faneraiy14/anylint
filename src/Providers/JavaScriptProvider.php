<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class JavaScriptProvider extends AbstractJsFamilyProvider
{
    public function name(): string
    {
        return 'JavaScript';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.js')
            || str_ends_with($filePath, '.jsx')
            || str_ends_with($filePath, '.mjs')
            || str_ends_with($filePath, '.cjs');
    }
}
