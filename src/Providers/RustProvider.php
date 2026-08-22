<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class RustProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Rust';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.rs');
    }

    protected function treeSitterLang(): string
    {
        return 'rust';
    }
}
