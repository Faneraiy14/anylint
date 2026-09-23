<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Ast\Node;
use AnyLint\Rules\EmptyFunctionRule;
use PHPUnit\Framework\TestCase;

/**
 * Мовно-незалежний тест на trait GenuineEmptinessCheck напряму через
 * ручно зібраний Node-вузол (без жодного провайдера) - перевіряє
 * фолбек, коли construct->line недійсний (напр. 0, як реально видавав
 * NyxilumProvider для struct-методів через баг у Parser.cs). Фолбек
 * МАЄ бути "не genuinely empty" (правило не спрацьовує), а не
 * "genuinely empty" (правило спрацьовує і мовчки ігнорує коментар) -
 * баг провайдера з номером рядка тоді деградує до false negative
 * (рідше ловить), а не false positive, що ще й ламає
 * коментар-силенсинг.
 */
final class GenuineEmptinessCheckTest extends TestCase
{
    public function testFunctionWithInvalidLineZeroAndCommentOnlyBodyIsNotFlagged(): void
    {
        $body = new Node('Block', 0, [], []);
        $func = new Node('FunctionDecl', 0, ['name' => 'update'], [$body]);
        $root = new Node('Root', 0, [], [$func]);

        $source = "struct S {\n    func update() {\n        // навмисно порожньо\n    }\n}\n";

        $findings = (new EmptyFunctionRule())->check($root, $source, 'test.nx');

        $this->assertCount(0, $findings);
    }

    public function testFunctionWithInvalidLineZeroAndTrulyEmptyBodyIsStillNotFlagged(): void
    {
        // Свідомий компроміс: коли рядок недійсний, ми взагалі не можемо
        // перевірити тіло через сирий текст - тож правило не спрацьовує
        // навіть для СПРАВДІ порожньої функції. Це прийнятний false
        // negative, поки провайдер не полагоджений (як тут - гарантує,
        // що баг провайдера ніколи не призведе до хибного знаходження).
        $body = new Node('Block', 0, [], []);
        $func = new Node('FunctionDecl', 0, ['name' => 'update'], [$body]);
        $root = new Node('Root', 0, [], [$func]);

        $source = "struct S {\n    func update() {\n    }\n}\n";

        $findings = (new EmptyFunctionRule())->check($root, $source, 'test.nx');

        $this->assertCount(0, $findings);
    }
}
