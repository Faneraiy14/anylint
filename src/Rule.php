<?php

declare(strict_types=1);

namespace AnyLint;

use AnyLint\Ast\Node;

/**
 * Правила бувають двох родів, обидва - той самий інтерфейс:
 *   - структурні (DeadCodeAfterReturnRule, EmptyCatchRule) - дивляться
 *     лише на $root (канонічне дерево), тому працюють ОДНАКОВО для будь-
 *     якої мови-провайдера без жодної зміни коду правила;
 *   - текстові (TodoTrackerRule, HardcodedSecretRule) - ігнорують $root
 *     і скануют $source як звичайний текст, тож працюють буквально для
 *     БУДЬ-ЯКОГО файлу, навіть без зареєстрованого провайдера мови;
 *   - мовно-специфічні (UnusedVariableRule) - читають $root->native,
 *     щоб дістатись деталей, які канонічна форма свідомо не несе.
 */
interface Rule
{
    public function name(): string;

    /** @return Finding[] */
    public function check(Node $root, string $source, string $filePath): array;
}
