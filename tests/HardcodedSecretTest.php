<?php

declare(strict_types=1);

namespace Tests;

final class HardcodedSecretTest extends AnalyzerTestCase
{
    public function testGitHubTokenIsFoundAndRedacted(): void
    {
        $f = $this->tempPhpFile('$t = "ghp_' . str_repeat('a', 36) . '";'); // anylint:ignore
        $findings = $this->newAnalyzer()->analyzePath($f);
        $secrets = $this->findingsFor($findings, 'hardcoded-secret');

        $this->assertCount(1, $secrets);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString(str_repeat('a', 36), $secret->message);
        }
    }

    public function testAnOrdinaryStringIsNotAFalsePositive(): void
    {
        $f = $this->tempPhpFile('$msg = "звичайний рядок без секретів";');
        $secrets = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'hardcoded-secret');
        $this->assertCount(0, $secrets);
    }

    public function testPhpArrayStyleQuotedKeyWithFatArrowIsFound(): void
    {
        // Ключ у лапках і присвоєння через "=>", а не голе "password =" -
        // раніше regex вимагав слово одразу перед "="/":", тож пропускав
        // саме такий поширений запис.
        $f = $this->tempPhpFile("\$config = ['password' => 'RealSecretValue123456'];"); // anylint:ignore
        $secrets = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'hardcoded-secret');
        $this->assertCount(1, $secrets);
    }
}
