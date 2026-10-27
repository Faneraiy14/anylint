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

    /**
     * false - мова не може жити в спільному довгоживучому процесі, і кожен
     * файл розбирається окремим одноразовим "node dump.js <мова> <файл>"
     * (див. LuaProvider). Повільніше (~0.2 с на файл), але коректно.
     */
    protected function usesPersistentWorker(): bool
    {
        return true;
    }

    private function dumpScriptPath(): string
    {
        return __DIR__ . '/../../tools/treesitter-ast-dump/dump.js';
    }

    public function parse(string $filePath): Node
    {
        // Один спільний node-процес на весь прогін (див. NodeWorker), а не
        // окремий запуск на кожен файл.
        $response = $this->usesPersistentWorker()
            ? NodeWorker::shared($this->nodeExecutable, $this->dumpScriptPath())
                ->request(['lang' => $this->treeSitterLang(), 'file' => $filePath])
            : $this->runOnce($filePath);

        // Код 3 - навмисний сигнал від dump.js "це справжня
        // синтаксична помилка в аналізованому файлі" (tree.rootNode.
        // hasError). Будь-який інший ненульовий код - проблема самого
        // інструментарію (типово: не виконано 'npm install' у
        // tools/treesitter-ast-dump/), а не файлу, що аналізується.
        if ($response['code'] === 3) {
            throw new \RuntimeException("Синтаксична помилка у {$filePath}: " . ($response['error'] ?? ''));
        }
        if ($response['code'] !== 0 || !isset($response['ast'])) {
            throw new \RuntimeException(
                "Не вдалось розібрати {$filePath} через 'node dump.js': " . ($response['error'] ?? 'порожня відповідь'),
            );
        }

        return $this->toNode($response['ast']);
    }

    /**
     * Одноразовий запуск dump.js на один файл - та сама форма відповіді,
     * що й у NodeWorker::request(), щоб parse() не розрізняв два шляхи.
     *
     * @return array{code: int, ast?: array<mixed>, error?: string}
     */
    private function runOnce(string $filePath): array
    {
        $process = proc_open(
            [$this->nodeExecutable, $this->dumpScriptPath(), $this->treeSitterLang(), $filePath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new \RuntimeException("Не вдалось запустити '{$this->nodeExecutable}' - переконайся, що Node.js у PATH.");
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $ast = $code === 0 ? json_decode($stdout, true) : null;
        if (is_array($ast)) {
            return ['code' => 0, 'ast' => $ast];
        }
        $error = trim($stdout . $stderr);
        if ($code !== 3) {
            $error .= "\nПеревір, що в " . \dirname($this->dumpScriptPath()) . " виконано 'npm install'.";
        }
        return ['code' => $code === 0 ? 2 : $code, 'error' => $error];
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
