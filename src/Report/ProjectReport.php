<?php

declare(strict_types=1);

namespace AnyLint\Report;

use AnyLint\AnalysisResult;
use AnyLint\FileResult;
use AnyLint\Finding;
use AnyLint\Severity;

/**
 * Зведення стану проєкту для "anylint report": не список знахідок, а
 * картина - скільки коду якими мовами, де найскладніші функції, які
 * файли найпроблемніші, скільки дубльованого коду.
 *
 * Модель - простий масив: вона ж і є JSON-вивід (--format json), а
 * текстовий і HTML-рендерери лише показують її. Baseline свідомо НЕ
 * застосовується: baseline ховає прийняті знахідки від CI-перевірки, а
 * звіт має показувати справжній стан коду, разом із прийнятим боргом.
 * Suppression-коментарі ж діють - це явне рішення "тут не проблема".
 *
 * @phpstan-type ReportData array{
 *     summary: array{files: int, linesOfCode: int, functions: int, findings: int},
 *     languages: list<array{language: string, files: int, linesOfCode: int}>,
 *     severities: array{error: int, warning: int, info: int},
 *     rules: list<array{rule: string, count: int}>,
 *     complexFunctions: list<array{file: string, line: int, name: string, cognitive: int, cyclomatic: int, statements: int}>,
 *     hotspots: list<array{file: string, score: int, errors: int, warnings: int, info: int, linesOfCode: int}>,
 *     duplication: array{fragments: int, duplicatedLines: int, percent: float, files: list<array{file: string, duplicatedLines: int}>},
 *     findings: list<array{file: string, line: int, rule: string, severity: string, message: string}>
 * }
 */
final class ProjectReport
{
    /** Скільки рядків у "топ"-списках (функції, файли). */
    public const TOP = 10;

    /** Мова для файлів без провайдера: конфіги, розмітка, скрипти. */
    public const OTHER_LANGUAGE = 'other';

    /** @return ReportData */
    public static function build(AnalysisResult $result, string $root): array
    {
        $files = array_values(array_filter(
            $result->files,
            // Бінарні файли (LOC 0 і без мови) - лише шум у звіті про код.
            static fn (FileResult $f) => $f->language !== null || $f->linesOfCode > 0,
        ));
        $relative = static fn (string $file) => self::relativePath($file, $root);

        return [
            'summary' => [
                'files' => count($files),
                'linesOfCode' => array_sum(array_map(static fn (FileResult $f) => $f->linesOfCode, $files)),
                'functions' => array_sum(array_map(static fn (FileResult $f) => count($f->functions), $files)),
                'findings' => count($result->findings),
            ],
            'languages' => self::languages($files),
            'severities' => self::severities($result->findings),
            'rules' => self::rules($result->findings),
            'complexFunctions' => self::complexFunctions($files, $relative),
            'hotspots' => self::hotspots($files, $relative),
            'duplication' => self::duplication($files, $relative),
            'findings' => array_values(array_map(static fn (Finding $f) => [
                'file' => $relative($f->file),
                'line' => $f->line,
                'rule' => $f->rule,
                'severity' => $f->severity->value,
                'message' => $f->message,
            ], $result->findings)),
        ];
    }

    public static function relativePath(string $file, string $root): string
    {
        $base = is_dir($root) ? rtrim($root, '/\\') . DIRECTORY_SEPARATOR : dirname($root) . DIRECTORY_SEPARATOR;
        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /**
     * @param list<FileResult> $files
     * @return list<array{language: string, files: int, linesOfCode: int}>
     */
    private static function languages(array $files): array
    {
        $byLanguage = [];
        foreach ($files as $file) {
            $language = $file->language ?? self::OTHER_LANGUAGE;
            $byLanguage[$language] ??= ['language' => $language, 'files' => 0, 'linesOfCode' => 0];
            $byLanguage[$language]['files']++;
            $byLanguage[$language]['linesOfCode'] += $file->linesOfCode;
        }
        $rows = array_values($byLanguage);
        usort($rows, static fn (array $a, array $b) => [$b['linesOfCode'], $a['language']] <=> [$a['linesOfCode'], $b['language']]);
        return $rows;
    }

    /**
     * @param array<Finding> $findings
     * @return array{error: int, warning: int, info: int}
     */
    private static function severities(array $findings): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $counts[$finding->severity->value]++;
        }
        return $counts;
    }

    /**
     * @param array<Finding> $findings
     * @return list<array{rule: string, count: int}>
     */
    private static function rules(array $findings): array
    {
        $counts = array_count_values(array_map(static fn (Finding $f) => $f->rule, $findings));
        $rows = [];
        foreach ($counts as $rule => $count) {
            $rows[] = ['rule' => (string) $rule, 'count' => $count];
        }
        usort($rows, static fn (array $a, array $b) => [$b['count'], $a['rule']] <=> [$a['count'], $b['rule']]);
        return $rows;
    }

    /**
     * @param list<FileResult> $files
     * @param \Closure(string): string $relative
     * @return list<array{file: string, line: int, name: string, cognitive: int, cyclomatic: int, statements: int}>
     */
    private static function complexFunctions(array $files, \Closure $relative): array
    {
        $rows = [];
        foreach ($files as $file) {
            foreach ($file->functions as $function) {
                if ($function->cognitive === 0) {
                    continue; // лінійна функція - не "складна", лише засмічувала б топ
                }
                $rows[] = [
                    'file' => $relative($file->file),
                    'line' => $function->line,
                    'name' => $function->name,
                    'cognitive' => $function->cognitive,
                    'cyclomatic' => $function->cyclomatic,
                    'statements' => $function->statements,
                ];
            }
        }
        usort($rows, static fn (array $a, array $b) => [$b['cognitive'], $b['cyclomatic'], $a['file'], $a['line']]
            <=> [$a['cognitive'], $a['cyclomatic'], $b['file'], $b['line']]);
        return array_slice($rows, 0, self::TOP);
    }

    /**
     * Найпроблемніші файли. Вага: помилка - 3, попередження - 1, info - 0
     * (info - це TODO/FIXME, вони не роблять файл "гарячим").
     *
     * @param list<FileResult> $files
     * @param \Closure(string): string $relative
     * @return list<array{file: string, score: int, errors: int, warnings: int, info: int, linesOfCode: int}>
     */
    private static function hotspots(array $files, \Closure $relative): array
    {
        $rows = [];
        foreach ($files as $file) {
            $counts = self::severities($file->findings);
            $score = 3 * $counts['error'] + $counts['warning'];
            if ($score === 0) {
                continue;
            }
            $rows[] = [
                'file' => $relative($file->file),
                'score' => $score,
                'errors' => $counts['error'],
                'warnings' => $counts['warning'],
                'info' => $counts['info'],
                'linesOfCode' => $file->linesOfCode,
            ];
        }
        usort($rows, static fn (array $a, array $b) => [$b['score'], $a['file']] <=> [$a['score'], $b['file']]);
        return array_slice($rows, 0, self::TOP);
    }

    /**
     * Рядки у дубльованих фрагментах (кожен рядок рахується раз, навіть
     * якщо входить у кілька фрагментів) - частка від усіх рядків коду.
     *
     * @param list<FileResult> $files
     * @param \Closure(string): string $relative
     * @return array{fragments: int, duplicatedLines: int, percent: float, files: list<array{file: string, duplicatedLines: int}>}
     */
    private static function duplication(array $files, \Closure $relative): array
    {
        $fragments = 0;
        $total = 0;
        $codeLines = 0;
        $rows = [];
        foreach ($files as $file) {
            $codeLines += $file->linesOfCode;
            $covered = [];
            foreach ($file->findings as $finding) {
                if ($finding->rule !== 'duplicate-code') {
                    continue;
                }
                $fragments++;
                foreach (range($finding->line, $finding->endLine ?? $finding->line) as $line) {
                    $covered[$line] = true;
                }
            }
            if ($covered !== []) {
                $rows[] = ['file' => $relative($file->file), 'duplicatedLines' => count($covered)];
                $total += count($covered);
            }
        }
        usort($rows, static fn (array $a, array $b) => [$b['duplicatedLines'], $a['file']] <=> [$a['duplicatedLines'], $b['file']]);
        return [
            'fragments' => $fragments,
            'duplicatedLines' => $total,
            // Фрагмент рахує й порожні рядки всередині, LOC - ні: обмежуємо 100%.
            'percent' => $codeLines === 0 ? 0.0 : min(100.0, round(100 * $total / $codeLines, 1)),
            'files' => array_slice($rows, 0, self::TOP),
        ];
    }
}
