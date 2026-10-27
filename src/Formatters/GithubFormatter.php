<?php

declare(strict_types=1);

namespace AnyLint\Formatters;

use AnyLint\Finding;
use AnyLint\Severity;

/**
 * Workflow-команди GitHub Actions ("::warning file=...,line=...::текст"):
 * GitHub сам показує їх анотаціями в диффі PR і на сторінці запуску.
 * На відміну від SARIF/Code Scanning, працює в будь-якому репозиторії,
 * зокрема приватному без GitHub Advanced Security, і не потребує прав
 * security-events.
 *
 * Шлях - відносно $baseDir (у GitHub Actions поточна тека - корінь
 * репозиторію), бо анотація прив'язується до файлу саме так.
 */
final class GithubFormatter
{
    /** @param Finding[] $findings */
    public static function format(array $findings, string $baseDir): string
    {
        $base = realpath($baseDir);
        $base = rtrim(str_replace('\\', '/', $base === false ? $baseDir : $base), '/') . '/';

        $out = '';
        foreach ($findings as $finding) {
            $command = match ($finding->severity) {
                Severity::Error => 'error',
                Severity::Warning => 'warning',
                Severity::Info => 'notice',
            };
            $properties = [
                'file' => self::relativePath($finding->file, $base),
                // GitHub ігнорує анотацію з line=0.
                'line' => (string) max(1, $finding->line),
            ];
            if ($finding->endLine !== null) {
                $properties['endLine'] = (string) max(1, $finding->endLine);
            }
            $properties['title'] = 'anylint ' . $finding->rule;

            $pairs = [];
            foreach ($properties as $name => $value) {
                $pairs[] = $name . '=' . self::escapeProperty($value);
            }
            $out .= "::{$command} " . implode(',', $pairs) . '::' . self::escapeData($finding->message) . "\n";
        }
        return $out;
    }

    private static function relativePath(string $file, string $base): string
    {
        $real = realpath($file);
        $path = str_replace('\\', '/', $real === false ? $file : $real);
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * Екранування з @actions/core (toCommandValue): без нього перенос
     * рядка в повідомленні обірвав би команду, а решта рядка стала б
     * звичайним текстом логу.
     */
    private static function escapeData(string $text): string
    {
        return str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $text);
    }

    private static function escapeProperty(string $text): string
    {
        return str_replace([':', ','], ['%3A', '%2C'], self::escapeData($text));
    }
}
