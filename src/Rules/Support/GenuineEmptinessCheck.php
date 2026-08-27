<?php

declare(strict_types=1);

namespace AnyLint\Rules\Support;

use AnyLint\Ast\Node;

/**
 * AST-порожнє (0 стейтментів-дітей) не завжди означає "нічого немає" -
 * коментар, що пояснює НАВІЩО тіло навмисно порожнє (напр. "//
 * очікувана помилка, ігноруємо"), в канонічному дереві не існує
 * взагалі (парсери не зберігають коментарі як вузли), тож AST бачить
 * такий catch/if/function так само, як і буквально порожній.
 *
 * Це навмисно НЕ per-мовна фіча в кожному провайдері (те довелось би
 * робити 16 разів різними інструментами - php-parser/TS compiler
 * API/tree-sitter кожен по-своєму зберігають коментарі), а простий
 * скан сирого $source в межах КОРОТКОГО вікна одразу після рядка
 * конструкції.
 *
 * $construct->line не завжди точно вказує на РЯДОК ВЛАСНОЇ фігурної
 * дужки конструкції (напр. NyxilumProvider віддає для CatchClause
 * рядок ОХОПЛЮЮЧОГО try, не самого catch; компактний однорядковий код
 * теж має кілька '{' на одному рядку) - тому НЕ довіряємо першій-ліпшій
 * '{' після цього рядка. Натомість пробуємо КОЖНУ '{' у вікні по черзі:
 * якщо пара дужок містить справжній код - це чиясь ЧУЖА, ширша
 * конструкція, пропускаємо і пробуємо наступну; якщо містить лише
 * коментар чи взагалі нічого - це і є шукане тіло.
 */
trait GenuineEmptinessCheck
{
    private function isGenuinelyEmpty(Node $construct, string $source): bool
    {
        $offset = $this->byteOffsetOfLine($source, $construct->line);
        if ($offset === null) {
            return true;
        }

        // ~кілька рядків - для "catch (e) {" з запасом на охоплюючий try
        // на попередньому рядку (той самий клас, що й Nyxilum-квірк),
        // але не настільки широко, щоб зачепити геть не пов'язаний код.
        $windowEnd = min(strlen($source), $offset + 400);
        $searchFrom = $offset;

        while (($openPos = strpos($source, '{', $searchFrom)) !== false && $openPos <= $windowEnd) {
            $closePos = $this->matchingBrace($source, $openPos);
            if ($closePos === null) {
                break;
            }
            $inner = substr($source, $openPos + 1, $closePos - $openPos - 1);
            if (trim($inner) === '') {
                return true;
            }
            if (trim($this->stripComments($inner)) === '') {
                return false;
            }
            // Реальний код усередині - це чужа, ширша конструкція
            // (напр. охоплюючий try чи function на тому ж рядку).
            // Пробуємо наступну '{' у вікні.
            $searchFrom = $openPos + 1;
        }
        return true;
    }

    private function matchingBrace(string $source, int $openPos): ?int
    {
        $depth = 0;
        for ($i = $openPos, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
    }

    /** Прибирає // .. і /* .. *\/ - охоплює більшість C-подібних мов з проєкту. */
    private function stripComments(string $text): string
    {
        $withoutBlock = preg_replace('#/\*.*?\*/#s', '', $text) ?? $text;
        return preg_replace('#//[^\n]*#', '', $withoutBlock) ?? $withoutBlock;
    }

    private function byteOffsetOfLine(string $source, int $line): ?int
    {
        if ($line < 1) {
            return null;
        }
        $offset = 0;
        $current = 1;
        $len = strlen($source);
        while ($current < $line) {
            $nl = strpos($source, "\n", $offset);
            if ($nl === false) {
                return null;
            }
            $offset = $nl + 1;
            $current++;
        }
        return $offset <= $len ? $offset : null;
    }
}
