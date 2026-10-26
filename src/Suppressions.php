<?php

declare(strict_types=1);

namespace AnyLint;

/**
 * Єдині коментарі-директиви для приглушення знахідок - для ВСІХ правил і
 * ВСІХ мов, бо розбираються з сирого тексту файлу, а не з AST. Три форми
 * (disable-next-line, disable-line, disable-file), приклади - у README:
 * буквальний приклад тут, у коментарі, сам спрацював би як директива при
 * self-analysis і приглушив би знахідки в цьому ж файлі.
 *
 * Без списку правил директива стосується всіх правил. Текст після " -- "
 * - пояснення для людей, не назви правил.
 *
 * Директива рахується лише коли ПРЯМО перед нею (з точністю до пробілів)
 * стоїть маркер коментаря: інакше рядковий літерал чи документація, що
 * згадує назву директиви, мовчки вимикали б перевірки. Набір маркерів
 * покриває всі мови провайдерів: // # /* * -- ; <!--
 *
 * Старий "anylint:ignore" (раніше розумів лише hardcoded-secret) лишився
 * як синонім disable-line для всіх правил - зворотна сумісність.
 */
final class Suppressions
{
    private const DIRECTIVE = '~(?://+|#+|/\*+|(?<![\w*])\*|--|;+|<!--)\s*anylint-disable-(next-line|line|file)\b(.*)$~';

    private const LEGACY = 'anylint:ignore';

    /** @var array<int, list<string>|true> рядок => правила (true = всі) */
    private array $lines = [];

    /** @var list<string>|true|null */
    private array|bool|null $file = null;

    public static function parse(string $source): self
    {
        $suppressions = new self();
        // Швидкий вихід для переважної більшості файлів без директив.
        if (!str_contains($source, 'anylint')) {
            return $suppressions;
        }

        foreach (explode("\n", $source) as $i => $line) {
            $lineNo = $i + 1;
            if (str_contains($line, self::LEGACY)) {
                $suppressions->add($lineNo, true);
            }
            if (preg_match(self::DIRECTIVE, $line, $m) !== 1) {
                continue;
            }
            $rules = self::parseRules($m[2]);
            match ($m[1]) {
                'next-line' => $suppressions->add($lineNo + 1, $rules),
                'line' => $suppressions->add($lineNo, $rules),
                'file' => $suppressions->addFile($rules),
            };
        }
        return $suppressions;
    }

    public function isSuppressed(Finding $finding): bool
    {
        return self::covers($this->file, $finding->rule)
            || self::covers($this->lines[$finding->line] ?? null, $finding->rule);
    }

    /** @return list<string>|true */
    private static function parseRules(string $rest): array|bool
    {
        $rest = explode(' -- ', $rest, 2)[0];
        $rest = str_replace(['*/', '-->'], ' ', $rest);
        $rules = [];
        foreach (preg_split('~[\s,]+~', $rest, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (preg_match('~^[a-z0-9][a-z0-9-]*$~', $token) === 1) {
                $rules[] = $token;
            }
        }
        return $rules === [] ? true : $rules;
    }

    /** @param list<string>|true $rules */
    private function add(int $line, array|bool $rules): void
    {
        $this->lines[$line] = self::merge($this->lines[$line] ?? null, $rules);
    }

    /** @param list<string>|true $rules */
    private function addFile(array|bool $rules): void
    {
        $this->file = self::merge($this->file, $rules);
    }

    /**
     * @param list<string>|true|null $existing
     * @param list<string>|true $new
     * @return list<string>|true
     */
    private static function merge(array|bool|null $existing, array|bool $new): array|bool
    {
        if ($existing === true || $new === true) {
            return true;
        }
        return array_values(array_unique([...($existing ?? []), ...$new]));
    }

    /** @param list<string>|true|null $scope */
    private static function covers(array|bool|null $scope, string $rule): bool
    {
        return $scope === true || (is_array($scope) && in_array($rule, $scope, true));
    }
}
