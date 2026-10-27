<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Report\HtmlReport;
use AnyLint\Report\ProjectReport;
use AnyLint\Report\TextReport;

/**
 * "anylint report": модель зведення (ProjectReport) і CLI-команда.
 */
final class ReportTest extends AnalyzerTestCase
{
    public function testReportSummarisesLanguagesComplexityHotspotsAndDuplication(): void
    {
        $dir = $this->tempDir();
        $shared = '';
        for ($i = 1; $i <= 8; $i++) {
            $shared .= "    compute(\$input, {$i});\n";
        }
        file_put_contents("{$dir}/a.php", "<?php\nfunction a(\$input) {\n{$shared}}\n");
        file_put_contents("{$dir}/b.php", "<?php\nfunction b(\$input) {\n{$shared}}\n");
        file_put_contents("{$dir}/c.php", "<?php\nfunction nested(\$x) {\n    if (\$x) {\n        foreach (\$x as \$y) {\n            g(\$y);\n        }\n    }\n}\n");
        file_put_contents("{$dir}/notes.txt", "just text\n");

        $report = ProjectReport::build($this->newAnalyzer()->analyze($dir), $dir);

        $this->assertSame(4, $report['summary']['files']);
        $this->assertSame(3, $report['summary']['functions']);
        $this->assertSame(['PHP', ProjectReport::OTHER_LANGUAGE], array_column($report['languages'], 'language'));
        $this->assertSame(['nested'], array_column($report['complexFunctions'], 'name'));
        $this->assertSame(3, $report['complexFunctions'][0]['cognitive']);
        $this->assertSame('c.php', $report['complexFunctions'][0]['file']);
        $this->assertSame(2, $report['duplication']['fragments']);
        $this->assertSame(16, $report['duplication']['duplicatedLines']);
        $this->assertSame(['a.php', 'b.php'], array_column($report['hotspots'], 'file'));
        $this->assertSame([['rule' => 'duplicate-code', 'count' => 2]], $report['rules']);
    }

    public function testRenderersShowTheModelAndEscapeHtml(): void
    {
        $file = $this->tempPhpFile("function f() {\n    \$a = '<script>';\n}\n");
        $report = ProjectReport::build($this->newAnalyzer()->analyze($file), $file);

        $html = HtmlReport::render($report, '<project>');
        $this->assertStringContainsString('&lt;project&gt;', $html);
        $this->assertStringNotContainsString('<project>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('unused-variable', $html);

        $text = TextReport::render($report);
        $this->assertStringContainsString('Файлів: 1', $text);
        $this->assertStringContainsString('unused-variable', $text);
    }

    public function testCliReportExitsZeroEvenWithFindingsAndWritesOutputFile(): void
    {
        $file = $this->tempPhpFile("function f() {\n    \$unused = 1;\n}\n");
        $output = dirname($file) . '/report.json';

        [$exit, $stdout] = $this->runCli(['report', $file, '--format', 'json', '--output', $output, '--no-cache']);

        $this->assertSame(0, $exit);
        $this->assertSame('', $stdout);
        $data = json_decode((string) file_get_contents($output), true);
        $this->assertIsArray($data);
        $this->assertSame(1, $data['summary']['findings']);
    }

    public function testCliRejectsReportOptionConflicts(): void
    {
        $file = $this->tempPhpFile('function f() { return 1; }');

        $this->assertSame(2, $this->runCli(['report', $file, '--format', 'pdf'])[0]);
        $this->assertSame(2, $this->runCli(['report', $file, '--fix'])[0]);
        $this->assertSame(2, $this->runCli([$file, '--format', 'html'])[0]);
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runCli(array $args): array
    {
        $command = array_merge([PHP_BINARY, __DIR__ . '/../bin/anylint'], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), trim((string) $stdout)];
    }
}
