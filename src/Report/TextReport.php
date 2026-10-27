<?php

declare(strict_types=1);

namespace AnyLint\Report;

/**
 * Звіт для терміналу: ті самі розділи, що й HTML, без списку всіх знахідок
 * (для нього є звичайний "anylint <шлях>").
 *
 * @phpstan-import-type ReportData from ProjectReport
 */
final class TextReport
{
    /** @param ReportData $report */
    public static function render(array $report): string
    {
        $s = $report['summary'];
        $out = "Звіт anylint\n\n";
        $out .= sprintf(
            "Файлів: %d   Рядків коду: %d   Функцій: %d   Знахідок: %d\n",
            $s['files'],
            $s['linesOfCode'],
            $s['functions'],
            $s['findings'],
        );
        $sev = $report['severities'];
        $out .= sprintf("  помилок: %d, попереджень: %d, info: %d\n", $sev['error'], $sev['warning'], $sev['info']);

        $out .= self::section('Мови', array_map(
            static fn (array $r) => sprintf('%-14s %6d файлів %9d рядків', $r['language'], $r['files'], $r['linesOfCode']),
            $report['languages'],
        ));
        $out .= self::section('Знахідки за правилами', array_map(
            static fn (array $r) => sprintf('%-28s %6d', $r['rule'], $r['count']),
            $report['rules'],
        ));
        $out .= self::section('Найскладніші функції (когнітивна / цикломатична)', array_map(
            static fn (array $r) => sprintf('%4d / %-4d %s  %s:%d', $r['cognitive'], $r['cyclomatic'], $r['name'], $r['file'], $r['line']),
            $report['complexFunctions'],
        ));
        $out .= self::section('Гарячі файли (помилка = 3, попередження = 1)', array_map(
            static fn (array $r) => sprintf('%4d  %s  (%d помилок, %d попереджень)', $r['score'], $r['file'], $r['errors'], $r['warnings']),
            $report['hotspots'],
        ));

        $dup = $report['duplication'];
        $out .= sprintf(
            "\nДублювання: %d фрагментів, %d рядків (%.1f%% коду)\n",
            $dup['fragments'],
            $dup['duplicatedLines'],
            $dup['percent'],
        );
        foreach ($dup['files'] as $row) {
            $out .= sprintf("  %6d  %s\n", $row['duplicatedLines'], $row['file']);
        }
        return $out;
    }

    /** @param list<string> $lines */
    private static function section(string $title, array $lines): string
    {
        if ($lines === []) {
            return "\n{$title}: немає\n";
        }
        return "\n{$title}\n  " . implode("\n  ", $lines) . "\n";
    }
}
