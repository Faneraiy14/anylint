<?php

declare(strict_types=1);

namespace AnyLint;

/**
 * glob-шаблони з "exclude" в anylint.json, семантика близька до .gitignore:
 *   - шаблон БЕЗ "/" ("generated", "*.min.js") порівнюється з кожною
 *     окремою частиною шляху - виключає таку теку чи файл на будь-якій
 *     глибині;
 *   - шаблон зі "/" ("legacy/old", "src/**\/*.gen.php") - зі шляхом
 *     відносно теки конфігу, причому збіг з будь-яким ПРЕФІКСОМ шляху
 *     виключає все під ним (як тека в .gitignore);
 *   - "*" - будь-що в межах однієї частини шляху, "**" - через кілька
 *     частин, "?" - один символ.
 */
final class PathMatcher
{
    /** @var list<string> regex для шаблонів без "/" */
    private array $segmentPatterns = [];

    /** @var list<string> regex для шаблонів зі "/" */
    private array $pathPatterns = [];

    /** @param list<string> $patterns */
    public function __construct(private readonly string $baseDir, array $patterns)
    {
        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', $pattern));
            $pattern = trim(str_starts_with($pattern, './') ? substr($pattern, 2) : $pattern, '/');
            if ($pattern === '') {
                continue;
            }
            if (str_contains($pattern, '/')) {
                $this->pathPatterns[] = self::toRegex($pattern);
            } else {
                $this->segmentPatterns[] = self::toRegex($pattern);
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->segmentPatterns === [] && $this->pathPatterns === [];
    }

    public function matches(string $path): bool
    {
        if ($this->isEmpty()) {
            return false;
        }
        $segments = $this->relativeSegments($path);

        foreach ($segments as $segment) {
            foreach ($this->segmentPatterns as $regex) {
                if (preg_match($regex, $segment) === 1) {
                    return true;
                }
            }
        }

        $prefix = '';
        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
            foreach ($this->pathPatterns as $regex) {
                if (preg_match($regex, $prefix) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return list<string> */
    private function relativeSegments(string $path): array
    {
        $real = realpath($path);
        $normalized = str_replace('\\', '/', $real === false ? $path : $real);
        $base = rtrim(str_replace('\\', '/', $this->baseDir), '/') . '/';
        if (str_starts_with($normalized, $base)) {
            $normalized = substr($normalized, strlen($base));
        }
        return array_values(array_filter(explode('/', $normalized), static fn (string $s) => $s !== '' && $s !== '.'));
    }

    private static function toRegex(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];
            if ($char === '*') {
                if (($glob[$i + 1] ?? '') === '*') {
                    $i++;
                    // "**/" - нуль або більше цілих частин шляху, тож
                    // "src/**/x.php" збігається і з "src/x.php".
                    if (($glob[$i + 1] ?? '') === '/') {
                        $i++;
                        $regex .= '(?:.*/)?';
                    } else {
                        $regex .= '.*';
                    }
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($char, '~');
            }
        }
        return '~^' . $regex . '$~';
    }
}
