<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Finding;
use AnyLint\Fixer;
use AnyLint\Formatters\SarifFormatter;
use AnyLint\Severity;

final class FixerAndSarifTest extends AnalyzerTestCase
{
    public function testFixerAppliesPromotableReturnTypeAndTheResultIsCleanValidPhp(): void
    {
        $f = $this->tempPhpFile(
            "class C {\n/**\n * @return string\n */\nfunction a() { return 'a'; }\n"
            . "/**\n * @return int\n */\nfunction b(\$x = (1 + 2)) { return 1; }\n}",
        );
        $findings = $this->newAnalyzer()->analyzePath($f);
        $this->assertCount(2, $this->findingsFor($findings, 'promotable-return-type'));

        $this->assertSame(2, Fixer::apply($findings));

        $source = (string) file_get_contents($f);
        $this->assertStringContainsString("function a(): string {", $source);
        $this->assertStringContainsString("function b(\$x = (1 + 2)): int {", $source);
        $this->assertSame([], $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'promotable-return-type'));
        exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code);
    }

    public function testOverlappingFixesKeepOnlyOneAndOutOfRangeIsSkipped(): void
    {
        $f = $this->tempFile('txt', 'abcdef');
        $fix = static fn (int $s, int $e, string $r) => new Finding($f, 1, 'x', Severity::Info, 'm', [
            'startOffset' => $s, 'endOffset' => $e, 'replacement' => $r,
        ]);

        $applied = Fixer::apply([$fix(1, 3, 'X'), $fix(2, 4, 'Y'), $fix(5, 99, 'Z')]);

        $this->assertSame(1, $applied);
        $this->assertSame('abYef', file_get_contents($f));
    }

    public function testSarifIsValidShapeWithRelativeUrisAndLevels(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/my src");
        file_put_contents("{$dir}/my src/a.php", '');
        $sarif = json_decode(SarifFormatter::format([
            new Finding("{$dir}/my src/a.php", 3, 'empty-catch', Severity::Warning, 'm1'),
            new Finding("{$dir}/my src/a.php", 0, 'todo-tracker', Severity::Info, 'm2'),
            new Finding("{$dir}/my src/a.php", 1, 'hardcoded-secret', Severity::Error, 'm3'),
        ], $dir), true);

        $this->assertIsArray($sarif);
        $this->assertSame('2.1.0', $sarif['version']);
        $run = $sarif['runs'][0];
        $this->assertSame('anylint', $run['tool']['driver']['name']);
        $ruleIds = array_column($run['tool']['driver']['rules'], 'id');
        $this->assertSame(['empty-catch', 'hardcoded-secret', 'todo-tracker'], $ruleIds);

        $this->assertSame(['warning', 'note', 'error'], array_column($run['results'], 'level'));
        $loc = $run['results'][0]['locations'][0]['physicalLocation'];
        $this->assertSame('my%20src/a.php', $loc['artifactLocation']['uri']);
        $this->assertSame(3, $loc['region']['startLine']);
        $this->assertSame(1, $run['results'][1]['locations'][0]['physicalLocation']['region']['startLine']);
        foreach ($run['results'] as $result) {
            $this->assertSame($result['ruleId'], $ruleIds[$result['ruleIndex']]);
        }
    }
}
