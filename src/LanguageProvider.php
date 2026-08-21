<?php

declare(strict_types=1);

namespace AnyLint;

use AnyLint\Ast\Node;

/**
 * Один плагін = одна мова. Analyzer обирає провайдера по supports($file),
 * а не по жорстко зашитому списку розширень - додати нову мову означає
 * написати новий клас, що реалізує цей інтерфейс, і зареєструвати його
 * (Analyzer::withProvider) - жодних змін у ядрі чи в існуючих правилах.
 */
interface LanguageProvider
{
    public function name(): string;

    public function supports(string $filePath): bool;

    /**
     * @throws \RuntimeException якщо файл не вдалось розібрати (синтаксична
     *         помилка в аналізованому коді) - Analyzer ловить це сам і
     *         перетворює на Finding, а не валить увесь прогін.
     */
    public function parse(string $filePath): Node;
}
