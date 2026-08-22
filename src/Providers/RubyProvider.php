<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class RubyProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Ruby';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.rb');
    }

    protected function treeSitterLang(): string
    {
        return 'ruby';
    }
}
