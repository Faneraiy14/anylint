<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Severity;

/**
 * Текстове правило: працює над $source напряму, ігноруючи $root - тому
 * діє на БУДЬ-якому файлі, навіть без зареєстрованого провайдера мови
 * для його розширення.
 */
final class TodoTrackerRule implements Rule
{
    public function name(): string
    {
        return 'todo-tracker';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $findings = [];
        foreach (explode("\n", $source) as $i => $line) {
            // Лише всередині коментаря (//, #, /* ... * / чи -- на тому
            // самому рядку - остання форма для Lua/SQL/Haskell) - інакше
            // "TODO"/"FIXME" як частина рядкового літералу чи ідентифікатора
            // (напр. "todo-tracker") теж спрацьовував би, як ловиться на
            // самому цьому файлі.
            //
            // (?<!https?:) - без цього рядок $url = "http://TODO.example.com"
            // теж давав знахідку: текстовий (не-AST) скан бачить "//"
            // одразу перед "TODO" й не може відрізнити справжній коментар
            // від "//", що просто трапилось усередині URL у рядковому
            // літералі.
            if (preg_match('~(?<!https?:)(?://|#|/\*|--)\s*(TODO|FIXME)\b[:\s]*(.*)~i', $line, $m) === 1) {
                $note = trim($m[2]);
                $findings[] = new Finding(
                    $filePath,
                    $i + 1,
                    $this->name(),
                    Severity::Info,
                    strtoupper($m[1]) . ($note !== '' ? ": {$note}" : ''),
                );
            }
        }
        return $findings;
    }
}
