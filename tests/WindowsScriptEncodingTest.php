<?php

declare(strict_types=1);

namespace Tests;

/**
 * Народжене з реальних живих багів того ж вечора: install-nx.ps1 без
 * UTF-8 BOM ламав парсинг у Windows PowerShell 5.1, окремий .bat з
 * кирилицею ламався в cmd.exe так само.
 */
final class WindowsScriptEncodingTest extends AnalyzerTestCase
{
    public function testPs1WithCyrillicAndNoBomIsFound(): void
    {
        $f = $this->tempFile('ps1', "# без BOM\nWrite-Host \"Привіт, світ\"\n");
        $enc = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'windows-script-encoding');
        $this->assertCount(1, $enc);
    }

    public function testPs1WithCyrillicAndBomIsNotFlagged(): void
    {
        $f = $this->tempFile('ps1', "\xEF\xBB\xBF# з BOM\nWrite-Host \"Привіт, світ\"\n");
        $enc = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'windows-script-encoding');
        $this->assertCount(0, $enc);
    }

    public function testPs1WithoutAnyNonAsciiDoesNotNeedABom(): void
    {
        $f = $this->tempFile('ps1', "# clean ASCII\nWrite-Host \"Hello, world\"\n");
        $enc = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'windows-script-encoding');
        $this->assertCount(0, $enc);
    }

    public function testBatWithCyrillicIsFoundRegardlessOfBom(): void
    {
        $f = $this->tempFile('bat', "@echo off\necho \xd0\x9f\xd1\x80\xd0\xb8\xd0\xb2\xd1\x96\xd1\x82\npause\n");
        $enc = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'windows-script-encoding');
        $this->assertCount(1, $enc);
    }

    public function testBatWithPureAsciiIsNotFlagged(): void
    {
        $f = $this->tempFile('bat', "@echo off\necho Hello\npause\n");
        $enc = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'windows-script-encoding');
        $this->assertCount(0, $enc);
    }

    public function testShIsNotAWindowsScriptTheRuleDoesNotApplyAtAll(): void
    {
        $f = $this->tempFile('sh', "#!/bin/bash\necho \"Привіт\"\n");
        $enc = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'windows-script-encoding');
        $this->assertCount(0, $enc);
    }
}
