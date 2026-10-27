<?php

declare(strict_types=1);

namespace AnyLint\Ast;

/**
 * Канонічне дерево з JSON зовнішнього процесу (dump.js, "nx ast"). Явна
 * перевірка форми замість сліпих (string)/(int)-кастів mixed: json_decode()
 * зі stdout іншого процесу - недовірені дані, і якщо дампер колись видасть
 * щось несподіване (регресія, обірваний вивід), краще зрозуміла помилка
 * тут, ніж мовчазне (string)null -> "" чи (int)"abc" -> 0 глибше в дереві.
 */
final class NodeDecoder
{
    /**
     * @param array<mixed> $data
     * @param string $origin звідки дерево - для повідомлень про помилку
     * @param (\Closure(string, list<Node>): list<Node>)|null $rewriteChildren
     *        мовна поправка дітей вузла (за типом) перед створенням Node
     * @throws \RuntimeException вузол без рядкового type чи цілого line
     */
    public static function decode(array $data, string $origin, ?\Closure $rewriteChildren = null): Node
    {
        $type = $data['type'] ?? null;
        if (!is_string($type)) {
            throw new \RuntimeException("Вузол AST від {$origin} без рядкового поля \"type\": " . json_encode($data));
        }

        $line = $data['line'] ?? null;
        if (!is_int($line)) {
            throw new \RuntimeException("Вузол '{$type}' від {$origin} без цілого поля \"line\".");
        }

        $attributes = [];
        if (is_array($data['attributes'] ?? null)) {
            foreach ($data['attributes'] as $key => $value) {
                $attributes[(string) $key] = $value;
            }
        }

        $children = [];
        if (is_array($data['children'] ?? null)) {
            foreach ($data['children'] as $child) {
                if (is_array($child)) {
                    $children[] = self::decode($child, $origin, $rewriteChildren);
                }
            }
        }
        if ($rewriteChildren !== null) {
            $children = $rewriteChildren($type, $children);
        }

        return new Node($type, $line, $attributes, $children);
    }
}
