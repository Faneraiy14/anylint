<?php

declare(strict_types=1);

namespace Tests;

use AnyLint\Baseline;

final class BaselineTest extends AnalyzerTestCase
{
    public function testBaselinedFindingsAreHiddenButNewOnesShow(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: old\n");
        $analyzer = $this->newAnalyzer();

        Baseline::fromFindings($analyzer->analyzePath($dir), $dir)->save("{$dir}/baseline.json");
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: old\n// TODO: new\n");

        $remaining = Baseline::fromFile("{$dir}/baseline.json")->filter($analyzer->analyzePath($dir));
        $this->assertCount(1, $remaining);
        $this->assertSame('TODO: new', $remaining[0]->message);
    }

    public function testLineShiftsDoNotResurrectBaselinedFindings(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: old\n");
        $analyzer = $this->newAnalyzer();
        Baseline::fromFindings($analyzer->analyzePath($dir), $dir)->save("{$dir}/baseline.json");

        file_put_contents("{$dir}/a.php", "<?php\n\$x = 1;\necho \$x;\n// TODO: old\n");

        $this->assertSame([], Baseline::fromFile("{$dir}/baseline.json")->filter($analyzer->analyzePath($dir)));
    }

    public function testASecondIdenticalFindingIsStillReported(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/a.php", "<?php\n// TODO: same\n");
        $analyzer = $this->newAnalyzer();
        Baseline::fromFindings($analyzer->analyzePath($dir), $dir)->save("{$dir}/baseline.json");

        file_put_contents("{$dir}/a.php", "<?php\n// TODO: same\n// TODO: same\n");

        $this->assertCount(1, Baseline::fromFile("{$dir}/baseline.json")->filter($analyzer->analyzePath($dir)));
    }

    public function testPathsAreStoredRelativeToTheBaselineFile(): void
    {
        $dir = $this->tempDir();
        mkdir("{$dir}/src");
        file_put_contents("{$dir}/src/a.php", "<?php\n// TODO: x\n");
        Baseline::fromFindings($this->newAnalyzer()->analyzePath($dir), $dir)->save("{$dir}/baseline.json");

        $data = json_decode((string) file_get_contents("{$dir}/baseline.json"), true);
        $this->assertIsArray($data);
        $this->assertSame('src/a.php', $data['findings'][0]['file']);
    }

    public function testMalformedBaselineIsAnError(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/baseline.json", '{"findings": [{"file": 1}]}');

        $this->expectException(\RuntimeException::class);
        Baseline::fromFile("{$dir}/baseline.json");
    }
}
