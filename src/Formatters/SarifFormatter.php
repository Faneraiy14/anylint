<?php

declare(strict_types=1);

namespace AnyLint\Formatters;

use AnyLint\Finding;
use AnyLint\Severity;

/**
 * SARIF 2.1.0 - стандартний (OASIS) формат результатів статичного аналізу.
 * Його напряму приймають GitHub Code Scanning (upload-sarif), GitLab,
 * Azure DevOps і VS Code SARIF Viewer - один формат замість окремої
 * інтеграції під кожну платформу.
 *
 * Шляхи віддаються ВІДНОСНИМИ (з "/") до $baseDir: GitHub зіставляє
 * результат з файлом репозиторію саме за відносним URI, абсолютний шлях
 * машини CI там нічого не означає.
 */
final class SarifFormatter
{
    private const SCHEMA = 'https://json.schemastore.org/sarif-2.1.0.json';

    /** @param Finding[] $findings */
    public static function format(array $findings, string $baseDir): string
    {
        $base = realpath($baseDir);
        $base = rtrim(str_replace('\\', '/', $base === false ? $baseDir : $base), '/') . '/';

        $ruleIds = array_values(array_unique(array_map(static fn (Finding $f) => $f->rule, $findings)));
        sort($ruleIds);

        $results = [];
        foreach ($findings as $finding) {
            $results[] = [
                'ruleId' => $finding->rule,
                'ruleIndex' => array_search($finding->rule, $ruleIds, true),
                'level' => match ($finding->severity) {
                    Severity::Error => 'error',
                    Severity::Warning => 'warning',
                    Severity::Info => 'note',
                },
                'message' => ['text' => $finding->message],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => self::uri($finding->file, $base)],
                        // SARIF вимагає startLine >= 1.
                        'region' => ['startLine' => max(1, $finding->line)],
                    ],
                ]],
            ];
        }

        $sarif = [
            '$schema' => self::SCHEMA,
            'version' => '2.1.0',
            'runs' => [[
                'tool' => ['driver' => [
                    'name' => 'anylint',
                    'informationUri' => 'https://github.com/Faneraiy14/anylint',
                    'rules' => array_map(static fn (string $id) => ['id' => $id, 'name' => $id], $ruleIds),
                ]],
                'results' => $results,
            ]],
        ];

        $json = json_encode(
            $sarif,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        return $json === false ? '' : $json . "\n";
    }

    private static function uri(string $file, string $base): string
    {
        $real = realpath($file);
        $path = str_replace('\\', '/', $real === false ? $file : $real);
        $prefix = '';
        if (str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        } elseif (str_starts_with($path, '/') || preg_match('~^[A-Za-z]:/~', $path) === 1) {
            // Файл поза $baseDir - відносного шляху немає, тож абсолютний
            // file:// URI, а не голий "/tmp/..." без схеми.
            $prefix = str_starts_with($path, '/') ? 'file://' : 'file:///';
        }
        // Пробіли, "#", кирилиця тощо в іменах - URI мусить бути закодований,
        // але "/" (і двокрапку диска Windows) лишаємо як є.
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        return $prefix . str_replace('%3A', ':', $encoded);
    }
}
