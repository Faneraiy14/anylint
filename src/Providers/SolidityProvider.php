<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class SolidityProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Solidity';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.sol');
    }

    protected function treeSitterLang(): string
    {
        return 'solidity';
    }
}
