<?php

declare(strict_types=1);

namespace AnyLint\Rules;

use AnyLint\Ast\Node;
use AnyLint\Finding;
use AnyLint\Rule;
use AnyLint\Severity;

/**
 * Текстове правило (як HardcodedSecretRule/TodoTrackerRule) - народжене
 * з реальних, живих багів за один вечір: install-nx.ps1 без UTF-8 BOM
 * ламав парсинг у Windows PowerShell 5.1 (кирилиця в Write-Host читалась
 * під системною кодовою сторінкою, і частина байтів випадково збігалась
 * із лапками/дужками - "Missing closing ')' in expression" на РЯДКАХ, що
 * складались лише з кирилиці), а окремий .bat з кирилицею всередині
 * ламався в cmd.exe так само (частина слова інтерпретувалась як окрема
 * команда). Обидва рази причина - не в PowerShell/cmd.exe самих по собі,
 * а в тому, що файл банально писався в неправильному кодуванні для свого
 * інтерпретатора.
 *
 * .ps1: не-ASCII (кирилиця тощо) БЕЗ UTF-8 BOM - проблема. З BOM - норм.
 * .bat/.cmd: не-ASCII взагалі, навіть з BOM - cmd.exe ненадійно читає
 * кодування батникового файлу незалежно від BOM (сам факт наявності BOM
 * не гарантує коректного парсингу в cmd.exe так, як гарантує в
 * PowerShell 5.1) - найнадійніша порада тут "уникай нелатиниці в .bat
 * взагалі", підтверджено на реальному запустити.bat цього ж вечора.
 */
final class WindowsScriptEncodingRule implements Rule
{
    public function name(): string
    {
        return 'windows-script-encoding';
    }

    public function check(Node $root, string $source, string $filePath): array
    {
        $ext = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        if ($ext === 'ps1') {
            return $this->checkPowerShell($source, $filePath);
        }
        if ($ext === 'bat' || $ext === 'cmd') {
            return $this->checkBatch($source, $filePath);
        }
        return [];
    }

    /** @return list<Finding> */
    private function checkPowerShell(string $source, string $filePath): array
    {
        $hasBom = str_starts_with($source, "\xEF\xBB\xBF");
        if ($hasBom) {
            return [];
        }
        $line = $this->firstNonAsciiLine($source);
        if ($line === null) {
            return [];
        }
        return [new Finding(
            $filePath,
            $line,
            $this->name(),
            Severity::Error,
            'PowerShell-скрипт містить не-ASCII символи (кирилицю тощо), але без UTF-8 BOM. ' .
            'Легасі Windows PowerShell 5.1 (типовий на звичайному Windows) без BOM читає файл ' .
            'під системною ANSI-кодовою сторінкою - частина байтів може випадково збігтись із ' .
            'лапками/дужками, ламаючи парсинг ("Missing closing \')\' in expression" на рядках, ' .
            'що є просто текстом). Додай BOM на початок файлу.',
        )];
    }

    /** @return list<Finding> */
    private function checkBatch(string $source, string $filePath): array
    {
        $line = $this->firstNonAsciiLine($source);
        if ($line === null) {
            return [];
        }
        return [new Finding(
            $filePath,
            $line,
            $this->name(),
            Severity::Error,
            '.bat/.cmd-файл містить не-ASCII символи (кирилицю тощо). cmd.exe ненадійно читає ' .
            'кодування batch-файлів незалежно від BOM - особливо в багаторядкових if(...)-блоках, ' .
            'де це може обірвати рядок команди на половині слова. Найнадійніше - тримати .bat/.cmd ' .
            'чистим ASCII (англійською).',
        )];
    }

    private function firstNonAsciiLine(string $source): ?int
    {
        $lines = explode("\n", $source);
        foreach ($lines as $i => $lineText) {
            if (preg_match('/[^\x00-\x7F]/', $lineText) === 1) {
                return $i + 1;
            }
        }
        return null;
    }
}
