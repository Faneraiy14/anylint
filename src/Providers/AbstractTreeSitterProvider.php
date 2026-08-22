<?php

declare(strict_types=1);

namespace AnyLint\Providers;

use AnyLint\Ast\Node;
use AnyLint\LanguageProvider;

/**
 * Спільна логіка для всієї родини C-подібних мов (C/C++/C#/Java): один
 * движок tree-sitter (через web-tree-sitter, БЕЗ нативної компіляції -
 * лише прекомпільовані .wasm-граматики з tree-sitter-wasms) обслуговує
 * всі чотири, тож tools/treesitter-ast-dump/dump.js бере МОВУ як перший
 * аргумент і сам обирає потрібну .wasm-граматику. LanguageProvider
 * лишається "один плагін - одна мова" з точки зору Analyzer
 * (name()/supports() різні в кожному підкласі) - тут лише спільний
 * виконавчий механізм, той самий принцип, що й у AbstractJsFamilyProvider.
 */
abstract class AbstractTreeSitterProvider implements LanguageProvider
{
    public function __construct(
        private readonly string $nodeExecutable = 'node',
    ) {
    }

    /** Ідентифікатор мови для LANG_CONFIG у dump.js: c/cpp/c_sharp/java. */
    abstract protected function treeSitterLang(): string;

    private function dumpScriptPath(): string
    {
        return __DIR__ . '/../../tools/treesitter-ast-dump/dump.js';
    }

    public function parse(string $filePath): Node
    {
        $process = proc_open(
            [$this->nodeExecutable, $this->dumpScriptPath(), $this->treeSitterLang(), $filePath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new \RuntimeException("Не вдалось запустити '{$this->nodeExecutable}' - переконайся, що Node.js у PATH.");
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Код виходу 3 - навмисний сигнал від dump.js "це справжня
        // синтаксична помилка в аналізованому файлі" (tree.rootNode.
        // hasError). Будь-який інший ненульовий код - проблема самого
        // інструментарію (типово: не виконано 'npm install' у
        // tools/treesitter-ast-dump/), а не файлу, що аналізується.
        if ($exitCode === 3) {
            throw new \RuntimeException("Синтаксична помилка у {$filePath}: " . trim($stdout . $stderr));
        }
        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Не вдалось розібрати {$filePath} через 'node dump.js': " . trim($stdout . $stderr)
                . "\nПеревір, що в " . \dirname($this->dumpScriptPath()) . " виконано 'npm install'.",
            );
        }

        $decoded = json_decode((string) $stdout, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("'{$this->nodeExecutable} " . $this->dumpScriptPath() . "' повернув невалідний JSON для {$filePath}");
        }

        return $this->toNode($decoded);
    }

    /**
     * Той самий підхід, що й у NyxilumProvider::toNode()/
     * AbstractJsFamilyProvider::toNode() - явна перевірка форми замість
     * сліпих кастів mixed, бо json_decode() із stdout зовнішнього процесу
     * це недовірені дані.
     *
     * @param array<mixed> $data
     */
    private function toNode(array $data): Node
    {
        $type = $data['type'] ?? null;
        if (!is_string($type)) {
            throw new \RuntimeException('Вузол AST від dump.js без рядкового поля "type": ' . json_encode($data));
        }

        $line = $data['line'] ?? null;
        if (!is_int($line)) {
            throw new \RuntimeException("Вузол '{$type}' від dump.js без цілого поля \"line\".");
        }

        $attributesRaw = $data['attributes'] ?? [];
        $attributes = is_array($attributesRaw) ? $this->toStringKeyedArray($attributesRaw) : [];

        $children = [];
        $childrenRaw = $data['children'] ?? [];
        if (is_array($childrenRaw)) {
            foreach ($childrenRaw as $child) {
                if (is_array($child)) {
                    $children[] = $this->toNode($child);
                }
            }
        }

        return new Node($type, $line, $attributes, $children);
    }

    /**
     * @param array<mixed> $raw
     * @return array<string, mixed>
     */
    private function toStringKeyedArray(array $raw): array
    {
        $result = [];
        foreach ($raw as $key => $value) {
            $result[(string) $key] = $value;
        }
        return $result;
    }
}
