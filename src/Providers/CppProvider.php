<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class CppProvider extends AbstractTreeSitterProvider
{
    private const EXTENSIONS = ['.cpp', '.cc', '.cxx', '.hpp', '.hh', '.hxx'];

    public function name(): string
    {
        return 'C++';
    }

    public function supports(string $filePath): bool
    {
        foreach (self::EXTENSIONS as $ext) {
            if (str_ends_with($filePath, $ext)) {
                return true;
            }
        }
        return false;
    }

    protected function treeSitterLang(): string
    {
        return 'cpp';
    }
}
