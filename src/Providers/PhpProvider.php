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
            // 🔥 ВИПРАВЛЕНО: class/interface/trait/enum (Stmt\ClassLike) не
            // мали ВЗАГАЛІ жодної гілки тут - провалювались у default нижче
            // й ставали непрозорим 'Other', тіло якого findNestedClosures()
            // НЕ обходить (він шукає лише Expr\Closure, не Stmt\ClassMethod).
            // Наслідок: жодне структурне правило (dead-code-after-return,
            // unused-variable, deep-nesting, тепер і promotable-return-type)
            // НІКОЛИ не бачило коду всередині методів класу - лише код у
            // функціях верхнього рівня. Для реального ООП PHP-коду (майже
            // весь Symfony/Laravel-стиль, зокрема OpenSourceBikeShare) це
            // означало, що структурні правила мовчки не аналізували
            // практично нічого. tests/run.php мав лише НЕГАТИВНІ тести на
            // класах (interface-метод без тіла, порожній __construct) -
            // вони проходили і тоді, коли клас узагалі не обходився, тому
            // діра лишалась непоміченою.
            $stmt instanceof Stmt\ClassLike => new Node(
                'Block',
                $line,
                [],
                [$this->convertStmts($stmt->stmts)],
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
                    ...array_values(array_map(fn (Stmt\Catch_ $c) => new Node(
                        'CatchClause',
                        $c->getLine(),
                        [],
                        [$this->convertStmts($c->stmts)],
                        $c,
                    ), $stmt->catches)),
                ],
            ),
            $stmt instanceof Stmt\If_ => new Node(
                'If',
                $line,
                [],
                [
                    $this->convertStmts($stmt->stmts),
                    ...($stmt->else !== null ? [$this->convertStmts($stmt->else->stmts)] : []),
                    ...array_values(array_map(fn (Stmt\ElseIf_ $ei) => $this->convertStmts($ei->stmts), $stmt->elseifs)),
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
     * @return list<Node>
     */
    private function findNestedClosures(PhpNode $expr): array
    {
        $found = (new NodeFinder())->find($expr, fn (PhpNode $n): bool => $n instanceof Expr\Closure);

        $closures = [];
        foreach ($found as $node) {
            // NodeFinder::find() повертає PhpNode[] навіть з фільтром-
            // предикатом - сам предикат PHPStan не звужує тип елементів,
            // тож instanceof тут потрібен саме для типів, а не лише як
            // "про всяк випадок" (усі елементи $found і так - Closure).
            if (!$node instanceof Expr\Closure) {
                continue;
            }
            $closures[] = new Node(
                'FunctionDecl',
                $node->getLine(),
                ['name' => '{closure}'],
                [$this->convertStmts($node->stmts)],
                $node,
            );
        }
        return $closures;
    }
}
