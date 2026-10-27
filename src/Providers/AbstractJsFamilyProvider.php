<?php

declare(strict_types=1);

namespace AnyLint\Providers;

use AnyLint\Ast\Node;
use AnyLint\Ast\NodeDecoder;
use AnyLint\LanguageProvider;

/**
 * Спільна логіка для JavaScriptProvider/TypeScriptProvider: обидва
 * розширення парсяться ОДНИМ інструментом (TypeScript compiler API вміє
 * і .js, і .ts - allowJs же лише про перевірку типів, парсер спільний),
 * тому дублювати "як запустити node dump.js і зробити json_decode" у
 * двох класах не було сенсу. LanguageProvider лишається "один плагін -
 * одна мова" з точки зору Analyzer (name()/supports() різні в кожному
 * підкласі) - тут лише спільний виконавчий механізм.
 */
abstract class AbstractJsFamilyProvider implements LanguageProvider
{
    public function __construct(
        private readonly string $nodeExecutable = 'node',
    ) {
    }

    private function dumpScriptPath(): string
    {
        return __DIR__ . '/../../tools/js-ast-dump/dump.js';
    }

    public function parse(string $filePath): Node
    {
        // Один спільний node-процес на весь прогін (див. NodeWorker), а не
        // окремий запуск на кожен файл.
        $response = NodeWorker::shared($this->nodeExecutable, $this->dumpScriptPath())
            ->request(['file' => $filePath]);

        // Код 3 - навмисний сигнал від dump.js "це справжня
        // синтаксична помилка в аналізованому файлі" (ts.SourceFile.
        // parseDiagnostics непорожній). Будь-який інший ненульовий код -
        // проблема самого інструментарію (типово: "Cannot find module
        // 'typescript'", бо в tools/js-ast-dump/ не було 'npm install'),
        // а не файлу, що аналізується - повідомлення про це не мало б
        // звучати як "у твоєму коді синтаксична помилка".
        if ($response['code'] === 3) {
            throw new \RuntimeException("Синтаксична помилка у {$filePath}: " . ($response['error'] ?? ''));
        }
        if ($response['code'] !== 0 || !isset($response['ast'])) {
            throw new \RuntimeException(
                "Не вдалось розібрати {$filePath} через 'node dump.js': " . ($response['error'] ?? 'порожня відповідь'),
            );
        }

        return NodeDecoder::decode($response['ast'], 'dump.js');
    }
}
