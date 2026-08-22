<?php

declare(strict_types=1);

namespace AnyLint\Providers;

final class DartProvider extends AbstractTreeSitterProvider
{
    public function name(): string
    {
        return 'Dart';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.dart');
    }

    protected function treeSitterLang(): string
    {
        return 'dart';
    }
}
