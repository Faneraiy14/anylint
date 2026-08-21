<?php

declare(strict_types=1);

namespace AnyLint\Providers;

use AnyLint\Ast\Node;
use AnyLint\LanguageProvider;

/**
 * "Доказ" того, що архітектура плагінів справді крос-мовна, а не лише
 * теоретично: NyxilumLang (github.com/Faneraiy14/NyxilumLang) - зовсім
 * інша мова з власним лексером/парсером/VM, і жодного зв'язку з PHP чи
 * nikic/php-parser. Замість переписування NyxilumLang-парсера на PHP -
 * шлях "nx ast файл.nx" (AstJsonDumper.cs) уже видає AST напряму в тій
 * самій канонічній JSON-схемі, яку тут очікує Node - конвертація тут
 * лише json_decode + пряме перетворення полів, БЕЗ мапування типів вузлів
 * (на відміну від PhpProvider, де php-parser->канонічна форма - реальна
 * робота). Це свідомий компроміс: спільна схема узгоджена НА СТОРОНІ
 * NyxilumLang, щоб не дублювати логіку "що є Block/Return/CatchClause" у
 * двох різних мовах реалізації.
 */
final class NyxilumProvider implements LanguageProvider
{
    public function __construct(
        private readonly string $nxExecutable = 'nx',
    ) {
    }

    public function name(): string
    {
        return 'NyxilumLang';
    }

    public function supports(string $filePath): bool
    {
        return str_ends_with($filePath, '.nx');
    }

    public function parse(string $filePath): Node
    {
        $process = proc_open(
            [$this->nxExecutable, 'ast', $filePath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new \RuntimeException("Не вдалось запустити '{$this->nxExecutable}' - переконайся, що Nx у PATH.");
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || str_starts_with(trim((string) $stdout), 'Parse Error')) {
            throw new \RuntimeException("Синтаксична помилка NyxilumLang у {$filePath}: " . trim($stdout . $stderr));
        }

        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("'{$this->nxExecutable} ast' повернув невалідний JSON для {$filePath}");
        }

        return $this->toNode($decoded);
    }

    /** @param array<string,mixed> $data */
    private function toNode(array $data): Node
    {
        $children = [];
        foreach ((array) ($data['children'] ?? []) as $child) {
            $children[] = $this->toNode($child);
        }
        return new Node(
            (string) $data['type'],
            (int) $data['line'],
            (array) ($data['attributes'] ?? []),
            $children,
        );
    }
}
