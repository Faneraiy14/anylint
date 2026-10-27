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

    /**
     * Граматика Lua з tree-sitter-wasms коректно розбирає лише ПЕРШИЙ файл
     * у процесі: кожен наступний parse() у тому ж WASM-модулі дає хибну
     * "синтаксичну помилку" на правильному коді (перевірено окремою
     * пробою на чистому web-tree-sitter; решта 14 мов так не ламаються).
     * Тому Lua лишається на одноразовому node на кожен файл.
     */
    protected function usesPersistentWorker(): bool
    {
        return false;
    }
}
