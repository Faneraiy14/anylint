<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class LuaProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Lua';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.lua');
    }

    protected function treeSitterLang(): string
    {
        return 'lua';
    }
}
