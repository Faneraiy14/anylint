<?php

declare(strict_types=1);

namespace AnyLint;

use AnyLint\Ast\Node;

/**
 * Правило, якому потрібен увесь проєкт, а не один файл (напр. пошук
 * дублювання між файлами). Працює у два кроки:
 *   1. collect() на кожному файлі збирає "факти" - їх кешують і
 *      передають з паралельних процесів разом із FileResult, тож факти
 *      мають бути лише скалярами/масивами;
 *   2. finish() після всіх файлів отримує факти всього проєкту й видає
 *      знахідки. Директиви приглушення і severity з конфігу до них
 *      застосовуються так само, як до звичайних.
 * check() з Rule для таких правил нічого не повертає.
 */
interface ProjectRule extends Rule
{
    /**
     * @param string|null $language назва провайдера; null - файл без парсера
     * @return mixed null - про цей файл фактів нема
     */
    public function collect(Node $root, string $source, string $filePath, ?string $language): mixed;

    /**
     * @param array<string, mixed> $factsByFile шлях => результат collect()
     * @return Finding[]
     */
    public function finish(array $factsByFile): array;
}
