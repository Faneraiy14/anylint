<?php

declare(strict_types=1);

namespace Tests;

final class DirectoryScanTest extends AnalyzerTestCase
{
    public function testRecursiveScanSkipsVendorAndGit(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/src', 0777, true);
        mkdir($dir . '/vendor', 0777, true);
        file_put_contents($dir . '/src/a.php', "<?php\nfunction f() { return 1; echo 'мертвий'; }");
        file_put_contents($dir . '/vendor/b.php', "<?php\nfunction f() { return 1; echo 'має бути пропущено'; }");

        $findings = $this->newAnalyzer()->analyzePath($dir);
        $dead = $this->findingsFor($findings, 'dead-code-after-return');
        $this->assertCount(1, $dead, 'only src/ should have been scanned');
    }

    public function testBuiltArtifactDirectoriesAreSkipped(): void
    {
        // dist/build/target/out - зібрані артефакти, не код.
        $dir = $this->tempDir();
        mkdir($dir . '/src', 0777, true);
        mkdir($dir . '/dist', 0777, true);
        file_put_contents($dir . '/src/a.php', "<?php\n// TODO: реальний todo\nfunction f() {}\n");
        file_put_contents($dir . '/dist/bundled.js', "// TODO: (комусь-там) зібраний сторонній рантайм, не має вважатись\n");

        $findings = $this->newAnalyzer()->analyzePath($dir);
        $todos = $this->findingsFor($findings, 'todo-tracker');
        $this->assertCount(1, $todos, 'dist/ should have been skipped');
    }

    public function testSymfonyVarCacheDirectoryIsSkipped(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/src', 0777, true);
        mkdir($dir . '/var/cache', 0777, true);
        file_put_contents($dir . '/src/a.php', "<?php\n// TODO: реальний todo\nfunction f() {}\n");
        file_put_contents($dir . '/var/cache/catalogue.php', "<?php\n// TODO: згенерований Symfony-кеш, не має вважатись\n\$password = 'слово-переклад';\n");

        $findings = $this->newAnalyzer()->analyzePath($dir);
        $todos = $this->findingsFor($findings, 'todo-tracker');
        $this->assertCount(1, $todos, 'var/ should have been skipped');
    }
}
