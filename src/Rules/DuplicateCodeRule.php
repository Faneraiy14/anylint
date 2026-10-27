<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\ConfigurableRule;
use AnyLint\Finding;
use AnyLint\ProjectRule;
use AnyLint\Severity;

/**
 * Дубльований код - між файлами і всередині файлу, для будь-якої мови.
 *
 * Шукається за НОРМАЛІЗОВАНИМ ТЕКСТОМ (як Simian/PMD CPD), а не за формою
 * канонічного AST: PhpProvider будує дерево лише до рівня стейтментів, тож
 * тисячі різних PHP-функцій мали б однакову "форму" (Assign, If, Return) і
 * стали б хибними дублікатами. Нормалізація: пробіли прибрано, порожні
 * рядки й рядки з самої пунктуації ("}", "});", "end" - ні, бо там літери)
 * пропущено. Ціна підходу: перейменовані змінні не ловляться.
 *
 * Лише файли з провайдером мови (код) - не JSON/XML/Markdown, де повтори
 * (переклади, конфіги) звичні й не є проблемою.
 */
final class DuplicateCodeRule implements ProjectRule, ConfigurableRule
{
    private const DEFAULT_MIN_LINES = 8;

    /**
     * Вікно, що трапляється в більш ніж стількох місцях, - шаблонний код
     * (ліцензійна шапка, однакові імпорти), а не корисна знахідка; до того
     * ж кожна пара таких місць дала б O(n^2) збігів.
     */
    private const MAX_OCCURRENCES = 10;

    /**
     * Імпорти, оголошення простору імен і "<?php": блок однакових "use X;" на
     * початку двох файлів - не дубльована логіка, а лише спільні
     * залежності, і винести його в "одну функцію" неможливо.
     */
    private const IMPORT_LINE = '/^\s*(?:<\?php|use|import|using|package|namespace|from\s+\S+\s+import|#\s*(?:include|import)'
        . '|require(?:_once)?|include(?:_once)?|extern\s+crate|@import)\b/';

    public function __construct(private readonly int $minLines = self::DEFAULT_MIN_LINES)
    {
    }

    public function withOptions(array $options): static
    {
        $value = $this->minLines;
        foreach ($options as $name => $option) {
            if ($name !== 'minLines') {
                throw new \InvalidArgumentException("невідома опція '{$name}' (є лише minLines).");
            }
            if (!is_int($option) || $option < 2) {
                throw new \InvalidArgumentException('minLines має бути цілим числом >= 2.');
            }
            $value = $option;
        }
        return new static($value);
    }

    public function name(): string
    {
        return 'duplicate-code';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        return [];
    }

    /** @return array{lines: list<int>, hashes: list<string>}|null */
    public function collect(Node $root, string $source, string $filePath, ?string $language): mixed
    {
        if ($language === null || str_contains($source, "\0")) {
            return null;
        }
        $lines = [];
        $normalized = [];
        foreach (explode("\n", $source) as $i => $line) {
            $compact = preg_replace('/\s+/', '', $line) ?? $line;
            // Лише рядки з літерою чи цифрою: "}" і "});" є в кожному файлі
            // й склеювали б різні фрагменти в один "дублікат".
            if (preg_match('/[\p{L}\p{N}]/u', $compact) !== 1 || preg_match(self::IMPORT_LINE, $line) === 1) {
                continue;
            }
            $lines[] = $i + 1;
            $normalized[] = $compact;
        }

        $hashes = [];
        for ($i = 0, $n = count($normalized) - $this->minLines; $i <= $n; $i++) {
            $hashes[] = substr(md5(implode("\n", array_slice($normalized, $i, $this->minLines))), 0, 16);
        }
        return $hashes === [] ? null : ['lines' => $lines, 'hashes' => $hashes];
    }

    public function finish(array $factsByFile): array
    {
        $files = self::validFacts($factsByFile);
        $locations = $this->mergeRuns($this->matchingPairs($files), $files);

        $findings = array_map(fn (array $location) => $this->toFinding($location), array_values($locations));
        usort($findings, static fn (Finding $a, Finding $b) => [$a->file, $a->line] <=> [$b->file, $b->line]);
        return $findings;
    }

    /**
     * Факти могли прийти з кешу чи дочірнього процесу - перевіряємо форму.
     *
     * @param array<string, mixed> $factsByFile
     * @return array<string, array{lines: list<int>, hashes: list<string>}>
     */
    private static function validFacts(array $factsByFile): array
    {
        $files = [];
        foreach ($factsByFile as $file => $facts) {
            if (is_array($facts) && is_array($facts['lines'] ?? null) && is_array($facts['hashes'] ?? null)) {
                $files[(string) $file] = [
                    'lines' => array_values(array_filter($facts['lines'], 'is_int')),
                    'hashes' => array_values(array_filter($facts['hashes'], 'is_string')),
                ];
            }
        }
        return $files;
    }

    /**
     * Усі пари однакових вікон. Ключ - "файлA\0позA\0файлB\0позB" з
     * (A, позA) < (B, позB), щоб кожна пара була рівно один раз.
     *
     * @param array<string, array{lines: list<int>, hashes: list<string>}> $files
     * @return array<string, array{0: string, 1: int, 2: string, 3: int}>
     */
    private function matchingPairs(array $files): array
    {
        /** @var array<string, list<array{0: string, 1: int}>> $index */
        $index = [];
        foreach ($files as $file => $facts) {
            foreach ($facts['hashes'] as $pos => $hash) {
                $index[$hash][] = [$file, $pos];
            }
        }

        $matches = [];
        foreach ($index as $occurrences) {
            $count = count($occurrences);
            if ($count < 2 || $count > self::MAX_OCCURRENCES) {
                continue;
            }
            for ($a = 0; $a < $count; $a++) {
                for ($b = $a + 1; $b < $count; $b++) {
                    $this->addPair($matches, $occurrences[$a], $occurrences[$b]);
                }
            }
        }
        return $matches;
    }

    /**
     * @param array<string, array{0: string, 1: int, 2: string, 3: int}> $matches
     * @param array{0: string, 1: int} $a
     * @param array{0: string, 1: int} $b
     */
    private function addPair(array &$matches, array $a, array $b): void
    {
        [$fileA, $posA] = $a;
        [$fileB, $posB] = $b;
        // Те саме місце, що перекриває саме себе (напр. 10 однакових
        // рядків поспіль), - не дублікат.
        if ($fileA === $fileB && abs($posA - $posB) < $this->minLines) {
            return;
        }
        $matches[self::pairKey($fileA, $posA, $fileB, $posB)] = [$fileA, $posA, $fileB, $posB];
    }

    /**
     * Склеює послідовні вікна в максимальні фрагменти: починає лише з
     * пари, у якої нема попередника (A-1, B-1), і йде вперед.
     *
     * @param array<string, array{0: string, 1: int, 2: string, 3: int}> $matches
     * @param array<string, array{lines: list<int>, hashes: list<string>}> $files
     * @return array<string, array{file: string, start: int, end: int, others: list<string>}>
     */
    private function mergeRuns(array $matches, array $files): array
    {
        $locations = [];
        foreach ($matches as [$fileA, $posA, $fileB, $posB]) {
            if (isset($matches[self::pairKey($fileA, $posA - 1, $fileB, $posB - 1)])) {
                continue;
            }
            $length = 1;
            while (isset($matches[self::pairKey($fileA, $posA + $length, $fileB, $posB + $length)])) {
                $length++;
            }
            $last = $length - 1 + $this->minLines - 1;
            $spanA = [$files[$fileA]['lines'][$posA], $files[$fileA]['lines'][$posA + $last]];
            $spanB = [$files[$fileB]['lines'][$posB], $files[$fileB]['lines'][$posB + $last]];
            self::addLocation($locations, $fileA, $spanA, "{$fileB}:{$spanB[0]}-{$spanB[1]}");
            self::addLocation($locations, $fileB, $spanB, "{$fileA}:{$spanA[0]}-{$spanA[1]}");
        }
        return $locations;
    }

    /** @param array{file: string, start: int, end: int, others: list<string>} $location */
    private function toFinding(array $location): Finding
    {
        $others = $location['others'];
        return new Finding(
            $location['file'],
            $location['start'],
            $this->name(),
            Severity::Warning,
            sprintf(
                'Дубльований фрагмент: рядки %d-%d (%d) повторюються в %s%s - винеси спільне в одну функцію.',
                $location['start'],
                $location['end'],
                $location['end'] - $location['start'] + 1,
                $others[0],
                count($others) > 1 ? sprintf(' і ще в %d місцях', count($others) - 1) : '',
            ),
            endLine: $location['end'],
        );
    }

    private static function pairKey(string $fileA, int $posA, string $fileB, int $posB): string
    {
        return $fileA . "\0" . $posA . "\0" . $fileB . "\0" . $posB;
    }

    /**
     * @param array<string, array{file: string, start: int, end: int, others: list<string>}> $locations
     * @param array{0: int, 1: int} $span
     */
    private static function addLocation(array &$locations, string $file, array $span, string $other): void
    {
        $key = $file . "\0" . $span[0] . "\0" . $span[1];
        $locations[$key] ??= ['file' => $file, 'start' => $span[0], 'end' => $span[1], 'others' => []];
        if (!in_array($other, $locations[$key]['others'], true)) {
            $locations[$key]['others'][] = $other;
        }
    }
}
