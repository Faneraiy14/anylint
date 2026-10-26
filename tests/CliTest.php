<?php

declare(strict_types=1);

namespace Tests;

final class CliTest extends AnalyzerTestCase
{
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
        $exitCode = proc_close($process);
        return [$exitCode, trim((string) $stdout)];
    }

    public function testACleanFileReportsOkAndExitsZero(): void
    {
        $f = $this->tempPhpFile('function f() { return 1; }');
        [$exitCode, $stdout] = $this->runCli([$f, '--json']);
        $decoded = json_decode($stdout, true);

        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
        $this->assertSame(0, $exitCode);
    }

    public function testAFileWithASecretReportsNotOkAndExitsOne(): void
    {
        $f = $this->tempPhpFile('$t = "ghp_' . str_repeat('a', 36) . '";'); // anylint:ignore
        [$exitCode, $stdout] = $this->runCli([$f, '--json']);
        $decoded = json_decode($stdout, true);

        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['ok']);
        $this->assertSame(1, $exitCode);
    }

    public function testHelpFlagExitsZeroAndMentionsAnylint(): void
    {
        [$exitCode, $stdout] = $this->runCli(['--help']);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('anylint', $stdout);
    }

    public function testJsonSurvivesInvalidUtf8InAFindingMessage(): void
    {
        // Раніше json_encode() без JSON_INVALID_UTF8_SUBSTITUTE повертав
        // false на невалідному байті - --json друкував ПОРОЖНІЙ рядок з
        // exit-кодом 1 (провал є, даних нема).
        $dir = $this->tempDir();
        $path = $dir . '/test.php';
        file_put_contents($path, "<?php\n// TODO: зробити щось \xffпоганим байтом\n");

        [, $stdout] = $this->runCli([$path, '--json']);
        $decoded = json_decode($stdout, true);

        $this->assertIsArray($decoded, '--json should return valid JSON, not an empty string');
        $this->assertCount(1, $decoded['findings']);
    }

    public function testConfigFileIsDiscoveredAndCanBeBypassed(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: x\n");
        file_put_contents("{$dir}/anylint.json", '{"rules": {"todo-tracker": "off"}}');

        [$exitCode] = $this->runCli([$dir, '--json']);
        $this->assertSame(0, $exitCode);

        [$exitCode] = $this->runCli([$dir, '--json', '--no-config']);
        $this->assertSame(1, $exitCode);
    }

    public function testExplicitConfigFlagAndInvalidConfigExitTwo(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: x\n");
        file_put_contents("{$dir}/good.json", '{"rules": {"todo-tracker": "off"}}');
        file_put_contents("{$dir}/bad.json", '{"rules": {"nope": "off"}}');

        [$exitCode] = $this->runCli(['--config', "{$dir}/good.json", $dir, '--json']);
        $this->assertSame(0, $exitCode);

        [$exitCode] = $this->runCli([$dir, "--config={$dir}/bad.json"]);
        $this->assertSame(2, $exitCode);
    }

    public function testGenerateBaselineThenOnlyNewFindingsFail(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: old\n");
        file_put_contents("{$dir}/anylint.json", '{"baseline": "anylint-baseline.json"}');

        [$exitCode] = $this->runCli([$dir, '--generate-baseline']);
        $this->assertSame(0, $exitCode);
        $this->assertFileExists("{$dir}/anylint-baseline.json");

        [$exitCode] = $this->runCli([$dir, '--json']);
        $this->assertSame(0, $exitCode, 'baselined finding should not fail the run');

        file_put_contents("{$dir}/a.php", "<?php\n// TODO: old\n// TODO: new\n");
        [$exitCode, $stdout] = $this->runCli([$dir, '--json']);
        $decoded = json_decode($stdout, true);
        $this->assertSame(1, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded['findings']);
    }

    public function testFixRewritesTheFileAndKeepsStdoutMachineReadable(): void
    {
        $f = $this->tempPhpFile("class C {\n/**\n * @return string\n */\nfunction a() { return 'a'; }\n}");

        [$exitCode, $stdout] = $this->runCli([$f, '--fix', '--json']);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['ok']);
        $this->assertStringContainsString('function a(): string {', (string) file_get_contents($f));
    }

    public function testSarifOutput(): void
    {
        $f = $this->tempPhpFile("// TODO: x\n");
        [$exitCode, $stdout] = $this->runCli([$f, '--sarif']);
        $decoded = json_decode($stdout, true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('todo-tracker', $decoded['runs'][0]['results'][0]['ruleId']);
    }
}
