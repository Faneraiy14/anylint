<?php

declare(strict_types=1);

namespace AnyLint;

/**
 * Список "прийнятих" знахідок - щоб впровадити anylint у живий проєкт,
 * не виправляючи спершу сотні старих проблем, і при цьому ловити кожну
 * НОВУ.
 *
 * Знахідка впізнається за (файл, правило, повідомлення) з лічильником, а
 * НЕ за номером рядка - той самий підхід, що й у PHPStan: інакше будь-
 * який доданий вище рядок зсував би всі старі знахідки, і вони
 * "оживали" б як нові. Лічильник - щоб друга така сама знахідка в тому
 * ж файлі (напр. ще один порожній catch) все одно була видна.
 *
 * Шляхи зберігаються відносно теки файлу baseline (з "/"), тож файл можна
 * комітити й запускати з будь-якої теки чи машини.
 */
final class Baseline
{
    private const VERSION = 1;

    /** @param array<string, int> $counts ключ (див. key()) => скільки таких знахідок прийнято */
    private function __construct(private readonly string $baseDir, private array $counts)
    {
    }

    /** @param Finding[] $findings */
    public static function fromFindings(array $findings, string $baseDir): self
    {
        $baseline = new self(self::normalizeDir($baseDir), []);
        foreach ($findings as $finding) {
            $key = $baseline->key($finding);
            $baseline->counts[$key] = ($baseline->counts[$key] ?? 0) + 1;
        }
        ksort($baseline->counts);
        return $baseline;
    }

    /** @throws \RuntimeException */
    public static function fromFile(string $file): self
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            throw new \RuntimeException("Не вдалось прочитати baseline: {$file}");
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("{$file}: невалідний JSON - {$e->getMessage()}");
        }
        $entries = is_array($data) ? ($data['findings'] ?? null) : null;
        if (!is_array($entries)) {
            throw new \RuntimeException("{$file}: очікується об'єкт з масивом 'findings'.");
        }

        $counts = [];
        foreach ($entries as $i => $entry) {
            if (
                !is_array($entry)
                || !is_string($entry['file'] ?? null)
                || !is_string($entry['rule'] ?? null)
                || !is_string($entry['message'] ?? null)
                || !is_int($entry['count'] ?? 1)
            ) {
                throw new \RuntimeException("{$file}: findings[{$i}] має містити рядки file, rule, message.");
            }
            $count = $entry['count'] ?? 1;
            $key = self::makeKey($entry['file'], $entry['rule'], $entry['message']);
            $counts[$key] = ($counts[$key] ?? 0) + $count;
        }
        return new self(self::normalizeDir(dirname($file)), $counts);
    }

    /** @throws \RuntimeException */
    public function save(string $file): void
    {
        $entries = [];
        foreach ($this->counts as $key => $count) {
            [$path, $rule, $message] = explode("\0", $key, 3) + ['', '', ''];
            $entries[] = ['file' => $path, 'rule' => $rule, 'message' => $message, 'count' => $count];
        }
        $json = json_encode(
            ['version' => self::VERSION, 'findings' => $entries],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($json === false || @file_put_contents($file, $json . "\n") === false) {
            throw new \RuntimeException("Не вдалось записати baseline: {$file}");
        }
    }

    public function count(): int
    {
        return array_sum($this->counts);
    }

    /**
     * @param Finding[] $findings
     * @return Finding[] лише знахідки, яких немає в baseline (або понад прийняту кількість)
     */
    public function filter(array $findings): array
    {
        $remaining = $this->counts;
        $result = [];
        foreach ($findings as $finding) {
            $key = $this->key($finding);
            if (($remaining[$key] ?? 0) > 0) {
                $remaining[$key]--;
                continue;
            }
            $result[] = $finding;
        }
        return $result;
    }

    private function key(Finding $finding): string
    {
        return self::makeKey($this->relativePath($finding->file), $finding->rule, $finding->message);
    }

    private static function makeKey(string $file, string $rule, string $message): string
    {
        return $file . "\0" . $rule . "\0" . $message;
    }

    private function relativePath(string $file): string
    {
        $real = realpath($file);
        $path = str_replace('\\', '/', $real === false ? $file : $real);
        return str_starts_with($path, $this->baseDir) ? substr($path, strlen($this->baseDir)) : $path;
    }

    private static function normalizeDir(string $dir): string
    {
        $real = realpath($dir);
        return rtrim(str_replace('\\', '/', $real === false ? $dir : $real), '/') . '/';
    }
}
