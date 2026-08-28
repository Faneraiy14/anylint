<?php

declare(strict_types=1);

namespace AnyLint\Formatters;

use AnyLint\Finding;

/**
 * Checkstyle XML - неофіційний стандарт обміну "файл:рядок + рівень +
 * повідомлення" між лінтерами й IDE (спершу окремий Java-лінтер, з часом
 * його формат виводу стали розуміти й інші інструменти незалежно від
 * мови). anylint сам не має нічого спільного з Java чи Checkstyle - формат
 * вибрано САМЕ тому, що PhpStorm (і інші JetBrains IDE) вміють імпортувати
 * його НАТИВНО (File Watcher -> "External annotations, Checkstyle-формат"),
 * без окремого JetBrains-плагіна. Такий плагін був би геть іншою кодовою
 * базою (IntelliJ Platform, Java/Kotlin) - anylint-vscode (VS Code
 * розширення в сусідньому репозиторії) цю платформу взагалі не покриває.
 */
final class CheckstyleFormatter
{
    /** @param Finding[] $findings */
    public static function format(array $findings): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('checkstyle');
        $root->setAttribute('version', '8.0');
        $doc->appendChild($root);

        // Групуємо за файлом - Checkstyle-схема хоче один <file> з
        // кількома <error> всередині, а не по одному <file> на знахідку.
        /** @var array<string, list<Finding>> $byFile */
        $byFile = [];
        foreach ($findings as $finding) {
            $byFile[$finding->file][] = $finding;
        }

        foreach ($byFile as $file => $fileFindings) {
            $fileEl = $doc->createElement('file');
            $fileEl->setAttribute('name', $file);
            foreach ($fileFindings as $finding) {
                $errorEl = $doc->createElement('error');
                $errorEl->setAttribute('line', (string) $finding->line);
                $errorEl->setAttribute('severity', $finding->severity->value);
                // setAttribute() сам екранує спецсимволи XML (<, &, "...) -
                // повідомлення Finding можуть містити довільний текст
                // (напр. уривок TODO-коментаря чи знайдений секрет), тож
                // ручна конкатенація рядків тут була б реальним ризиком
                // зламаного/невалідного XML на конкретному вмісті файлу.
                $errorEl->setAttribute('message', $finding->message);
                $errorEl->setAttribute('source', 'anylint.' . $finding->rule);
                $fileEl->appendChild($errorEl);
            }
            $root->appendChild($fileEl);
        }

        $xml = $doc->saveXML();
        return $xml !== false ? $xml : '';
    }
}
