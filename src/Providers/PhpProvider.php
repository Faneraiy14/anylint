<?php

declare(strict_types=1);

namespace AnyLint\Providers;

use AnyLint\Ast\Node;
use AnyLint\LanguageProvider;
use PhpParser\Error as PhpParseError;
use PhpParser\Node as PhpNode;
use PhpParser\Node\Stmt;
use PhpParser\Node\Expr;
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
            $stmt instanceof Stmt\Return_ => new Node('Return', $line, [], [], $stmt),
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
            $stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\Assign => new Node(
                'Assign',
                $line,
                [],
                [],
                $stmt->expr,
            ),
            default => new Node('Other', $line, ['kind' => $stmt::class], [], $stmt),
        };
    }
}
