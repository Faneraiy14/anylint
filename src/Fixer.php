<?php

declare(strict_types=1);

namespace AnyLint;

/**
 * Застосовує Finding::$fix до файлів на диску (--fix). Зсуви в fix -
 * байтові, відносно вмісту файлу на момент аналізу, тож:
 *   - правки одного файлу застосовуються з КІНЦЯ до початку - вставка
 *     ближче до початку зсунула б зсуви всіх наступних;
 *   - правка, що перетинається з уже прийнятою, пропускається (дві
 *     правила не мають права переписати той самий шматок по-різному) -
 *     наступний запуск --fix підхопить її на вже оновленому файлі.
 */
final class Fixer
{
    /**
     * @param Finding[] $findings
     * @return int скільки правок застосовано
     * @throws \RuntimeException файл не вдалось перечитати чи записати
     */
    public static function apply(array $findings): int
    {
        /** @var array<string, list<array{startOffset: int, endOffset: int, replacement: string}>> $byFile */
        $byFile = [];
        foreach ($findings as $finding) {
            if ($finding->fix !== null) {
                $byFile[$finding->file][] = $finding->fix;
            }
        }

        $applied = 0;
        foreach ($byFile as $file => $fixes) {
            $source = @file_get_contents($file);
            if ($source === false) {
                throw new \RuntimeException("--fix: не вдалось прочитати {$file}");
            }
            usort($fixes, static fn (array $a, array $b) => $b['startOffset'] <=> $a['startOffset']);

            $limit = strlen($source);
            foreach ($fixes as $fix) {
                if ($fix['startOffset'] < 0 || $fix['startOffset'] > $fix['endOffset'] || $fix['endOffset'] > $limit) {
                    continue;
                }
                $source = substr($source, 0, $fix['startOffset']) . $fix['replacement'] . substr($source, $fix['endOffset']);
                $limit = $fix['startOffset'];
                $applied++;
            }

            if (@file_put_contents($file, $source) === false) {
                throw new \RuntimeException("--fix: не вдалось записати {$file}");
            }
        }
        return $applied;
    }
}
