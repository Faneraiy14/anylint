<?php

declare(strict_types=1);

namespace AnyLint\Providers;

use AnyLint\Ast\Node;
use AnyLint\LanguageProvider;
use PhpParser\Error as PhpParseError;
use PhpParser\Node as PhpNode;
use PhpParser\Node\Stmt;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

final class PhpProvider implements LanguageProvider
{
    public function name(): string
    {
        return 'PHP';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.php');
    }

    public function parse(string $filePath): Node
    {
        $source = file_get_contents($filePath);
        if ($source === false) {
            throw new \RuntimeException("Не вдалось прочитати файл: {$filePath}");
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($source) ?? [];
        } catch (PhpParseError $e) {
            throw new \RuntimeException("Синтаксична помилка PHP у {$filePath}: {$e->getMessage()}");
        }

        return new Node('Root', 1, [], [$this->convertStmts($ast)]);
    }

    /** @param PhpNode\Stmt[] $stmts */
    private function convertStmts(array $stmts): Node
    {
        $line = $stmts !== [] ? $stmts[0]->getLine() : 0;
        $children = [];
        foreach ($stmts as $stmt) {
            $children[] = $this->convertStmt($stmt);
        }
        return new Node('Block', $line, [], $children);
    }

    private function convertStmt(PhpNode $stmt): Node
    {
        $line = $stmt->getLine();

        return match (true) {
            $stmt instanceof Stmt\Function_, $stmt instanceof Stmt\ClassMethod => new Node(
                'FunctionDecl',
                $line,
                ['name' => $stmt->name->name],
                $stmt->stmts !== null ? [$this->convertStmts($stmt->stmts)] : [],
                $stmt,
            ),
            // Замикання в значенні return: "return function() { ... };".
            $stmt instanceof Stmt\Return_ => new Node(
                'Return',
                $line,
                [],
                $stmt->expr !== null ? $this->findNestedClosures($stmt->expr) : [],
                $stmt,
            ),
            $stmt instanceof Stmt\TryCatch => new Node(
                'TryCatch',
                $line,
                [],
                [
                    $this->convertStmts($stmt->stmts),
                    ...array_map(fn (Stmt\Catch_ $c) => new Node(
                        'CatchClause',
                        $c->getLine(),
                        [],
                        [$this->convertStmts($c->stmts)],
                        $c,
                    ), $stmt->catches),
                ],
            ),
            $stmt instanceof Stmt\If_ => new Node(
                'If',
                $line,
                [],
                [
                    $this->convertStmts($stmt->stmts),
                    ...($stmt->else !== null ? [$this->convertStmts($stmt->else->stmts)] : []),
                    ...array_map(fn (Stmt\ElseIf_ $ei) => $this->convertStmts($ei->stmts), $stmt->elseifs),
                ],
            ),
            $stmt instanceof Stmt\While_ => new Node('While', $line, [], [$this->convertStmts($stmt->stmts)]),
            $stmt instanceof Stmt\Do_ => new Node('Do', $line, [], [$this->convertStmts($stmt->stmts)]),
            $stmt instanceof Stmt\For_ => new Node('For', $line, [], [$this->convertStmts($stmt->stmts)]),
            $stmt instanceof Stmt\Foreach_ => new Node('Foreach', $line, [], [$this->convertStmts($stmt->stmts)]),
            // Замикання у правій частині присвоєння: "$fn = function() {...};".
            $stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\Assign => new Node(
                'Assign',
                $line,
                [],
                $this->findNestedClosures($stmt->expr->expr),
                $stmt->expr,
            ),
            // Замикання-аргументи ("usort($a, function(){...})",
            // "array_map(function(){...}, $a)") потрапляють САМЕ сюди -
            // виклик функції як окремий стейтмент не має власного case
            // вище, тож без findNestedClosures() тіло такого замикання
            // було б цілком невидиме для структурних правил (dead-code-
            // after-return/empty-catch не бачили б нічого всередині).
            default => new Node(
                'Other',
                $line,
                ['kind' => $stmt::class],
                $this->findNestedClosures($stmt),
                $stmt,
            ),
        };
    }

    /**
     * Знаходить замикання (Expr\Closure) БУДЬ-ДЕ у виразі - аргумент
     * виклику, елемент масиву, тернарник тощо - і перетворює тіло кожного
     * в такий самий канонічний FunctionDecl, що й звичайна функція, тож
     * dead-code-after-return/empty-catch бачать середину замикань так
     * само, як середину звичайних function-оголошень.
     *
     * НЕ викликається для стейтментів, що вже рекурсивно конвертуються
     * через ->stmts (Function_/TryCatch/If/While/...) - їхні ВЛАСНІ
     * вкладені стейтменти пройдуть через convertStmt() і самі знайдуть
     * замикання на своєму рівні; повторний виклик тут спричинив би
     * дублювання знахідок (і зайву роботу) для кожного рівня вкладеності.
     *
     * @return Node[]
     */
    private function findNestedClosures(PhpNode $expr): array
    {
        $closures = (new NodeFinder())->find($expr, fn (PhpNode $n) => $n instanceof Expr\Closure);
        return array_map(fn (Expr\Closure $c) => new Node(
            'FunctionDecl',
            $c->getLine(),
            ['name' => '{closure}'],
            [$this->convertStmts($c->stmts)],
            $c,
        ), $closures);
    }
}
