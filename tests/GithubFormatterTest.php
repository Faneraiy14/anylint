<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Finding;
use AnyLint\Formatters\GithubFormatter;
use AnyLint\Severity;

final class GithubFormatterTest extends AnalyzerTestCase
{
    public function testFindingsBecomeWorkflowCommandsWithRelativePaths(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/src");
        file_put_contents("{$dir}/src/a.php", '<?php');

        $out = GithubFormatter::format([
            new Finding("{$dir}/src/a.php", 3, 'empty-catch', Severity::Warning, 'Порожній catch'),
            new Finding("{$dir}/src/a.php", 0, 'parse-error', Severity::Error, 'зламано'),
            new Finding("{$dir}/src/a.php", 5, 'todo-tracker', Severity::Info, 'TODO'),
            new Finding("{$dir}/src/a.php", 10, 'duplicate-code', Severity::Warning, 'дубль', endLine: 20),
        ], $dir);

        $this->assertSame(
            "::warning file=src/a.php,line=3,title=anylint empty-catch::Порожній catch\n"
            . "::error file=src/a.php,line=1,title=anylint parse-error::зламано\n"
            . "::notice file=src/a.php,line=5,title=anylint todo-tracker::TODO\n"
            . "::warning file=src/a.php,line=10,endLine=20,title=anylint duplicate-code::дубль\n",
            $out,
        );
    }

    /**
     * Перенос рядка в повідомленні обірвав би команду, а "," чи ":" в
     * імені файлу - список властивостей.
     */
    public function testMessagesAndPropertiesAreEscaped(): void
    {
        $out = GithubFormatter::format(
            [new Finding('/elsewhere/a,b:c.php', 1, 'x', Severity::Error, "50% done\r\nnext line")],
            '/root',
        );

        $this->assertSame("::error file=/elsewhere/a%2Cb%3Ac.php,line=1,title=anylint x::50%25 done%0D%0Anext line\n", $out);
    }
}
