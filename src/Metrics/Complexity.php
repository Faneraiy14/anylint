<?php

declare(strict_types=1);

namespace AnyLint\Metrics;

use AnyLint\Ast\Node;

/**
 * Складність керування потоком, порахована з КАНОНІЧНОГО дерева, тож
 * однаково для кожної мови з FunctionDecl. Чесне обмеження: рахуються
 * лише канонічні розгалуження - If, For, Foreach, While, Do, CatchClause.
 * Логічні && / || і гілки switch/case НЕ рахуються: PhpProvider будує
 * дерево лише до рівня стейтментів, і їх там просто не видно, а рахувати
 * їх лише для частини мов зробило б числа непорівнянними між мовами.
 *
 *  - cyclomatic: 1 + кількість розгалужень (McCabe без логічних операторів);
 *  - cognitive: кожне розгалуження дає +1 і ще +1 за кожен рівень
 *    вкладеності, у якому воно стоїть (ідея Sonar Cognitive Complexity:
 *    глибоко вкладене розгалуження читати важче, ніж послідовні). Ланцюжок
 *    "else if" рахується як продовження, а не як нова вкладеність - так
 *    само, як у DeepNestingRule.
 *
 * Вкладені функції (замикання) не додаються до охоплюючої - у кожної
 * свої метрики.
 */
final class Complexity
{
    /** @var list<string> */
    private const BRANCHES = ['If', 'For', 'Foreach', 'While', 'Do', 'CatchClause'];

    /** @return list<FunctionMetrics> */
    public static function functions(Node $root): array
    {
        $result = [];
        foreach ($root->findAll('FunctionDecl') as $function) {
            $name = $function->attributes['name'] ?? null;
            $cognitive = 0;
            self::walkCognitive($function, 0, $cognitive);
            $result[] = new FunctionMetrics(
                is_string($name) ? $name : '?',
                $function->line,
                1 + self::countBranches($function),
                $cognitive,
                self::topLevelStatements($function),
            );
        }
        return $result;
    }

    /**
     * Непорожні рядки. 0 для бінарних файлів (NUL-байт): "рядки" .png чи
     * .jar нічого не означають і лише спотворили б звіт.
     */
    public static function linesOfCode(string $source): int
    {
        if (str_contains($source, "\0")) {
            return 0;
        }
        // false - лише при внутрішній помилці PCRE (напр. ліміт
        // backtracking на гігантському файлі): тоді рядків просто не знаємо.
        $count = preg_match_all('/^[ \t]*\S/m', $source);
        return $count === false ? 0 : $count;
    }

    private static function countBranches(Node $node): int
    {
        $count = 0;
        foreach ($node->children as $child) {
            if ($child->type === 'FunctionDecl') {
                continue;
            }
            if (in_array($child->type, self::BRANCHES, true)) {
                $count++;
            }
            $count += self::countBranches($child);
        }
        return $count;
    }

    private static function walkCognitive(Node $node, int $nesting, int &$score): void
    {
        foreach ($node->children as $child) {
            if ($child->type === 'FunctionDecl') {
                continue;
            }
            if (!in_array($child->type, self::BRANCHES, true)) {
                self::walkCognitive($child, $nesting, $score);
                continue;
            }
            if ($node->type === 'If' && $child->type === 'If') {
                // "else if" - продовження того самого ланцюжка.
                $score += 1;
                self::walkCognitive($child, $nesting, $score);
                continue;
            }
            $score += 1 + $nesting;
            self::walkCognitive($child, $nesting + 1, $score);
        }
    }

    private static function topLevelStatements(Node $function): int
    {
        foreach ($function->children as $child) {
            if ($child->type === 'Block') {
                return count($child->children);
            }
        }
        return 0;
    }
}
