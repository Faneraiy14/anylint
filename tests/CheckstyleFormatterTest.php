<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Finding;
use AnyLint\Formatters\CheckstyleFormatter;
use AnyLint\Severity;
use PHPUnit\Framework\TestCase;

/**
 * Навіщо: PhpStorm/JetBrains IDE не читають наш --json, зате вміють
 * нативно імпортувати Checkstyle XML через File Watcher - без окремого
 * плагіна на IntelliJ Platform.
 */
final class CheckstyleFormatterTest extends TestCase
{
    public function testGroupsFindingsByFileAndEscapesSpecialCharacters(): void
    {
        $xml = CheckstyleFormatter::format([
            new Finding('src/A.php', 3, 'empty-catch', Severity::Warning, 'Порожній catch'),
            new Finding('src/A.php', 10, 'hardcoded-secret', Severity::Error, 'Знайдено секрет'),
            new Finding('src/B.php', 1, 'todo-tracker', Severity::Info, 'TODO: <fix> & "escape" me'),
        ]);

        $sxml = simplexml_load_string($xml);
        $this->assertNotFalse($sxml);

        $files = $sxml->xpath('/checkstyle/file');
        $this->assertCount(2, $files);

        $fileA = $sxml->xpath('/checkstyle/file[@name="src/A.php"]');
        $this->assertCount(1, $fileA);
        $errorsA = $fileA[0]->xpath('error');
        $this->assertCount(2, $errorsA);
        $this->assertSame('3', (string) $errorsA[0]['line']);
        $this->assertSame('warning', (string) $errorsA[0]['severity']);
        $this->assertSame('Порожній catch', (string) $errorsA[0]['message']);
        $this->assertSame('anylint.empty-catch', (string) $errorsA[0]['source']);

        $fileB = $sxml->xpath('/checkstyle/file[@name="src/B.php"]');
        $this->assertCount(1, $fileB);
        $errorsB = $fileB[0]->xpath('error');
        $this->assertCount(1, $errorsB);
        $this->assertSame('TODO: <fix> & "escape" me', (string) $errorsB[0]['message']);
    }

    public function testNoFindingsProducesValidXmlWithNoFileElements(): void
    {
        $xml = CheckstyleFormatter::format([]);
        $sxml = simplexml_load_string($xml);
        $this->assertNotFalse($sxml);
        $this->assertCount(0, $sxml->xpath('/checkstyle/file'));
    }
}
