<?php

declare(strict_types=1);

namespace Tests;

final class SuppressionsTest extends AnalyzerTestCase
{
    private const EMPTY_CATCH = "try { f(); } catch (\\Exception \$e) {}\n";

    public function testDisableNextLineSilencesOnlyTheNextLine(): void
    {
        $f = $this->tempPhpFile(
            "// anylint-disable-next-line empty-catch\n"
            . self::EMPTY_CATCH
            . self::EMPTY_CATCH,
        );
        $findings = $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-catch');

        $this->assertCount(1, $findings);
        $this->assertSame(4, $findings[0]->line);
    }

    public function testDisableLineWithoutRulesSilencesEveryRuleOnThatLine(): void
    {
        $f = $this->tempPhpFile("try { f(); } catch (\\Exception \$e) {} // TODO: x anylint-disable-line\n");
        // Директива має стояти ОДРАЗУ після маркера коментаря - тут перед
        // нею "TODO: x", тож вона не рахується.
        $this->assertNotSame([], $this->newAnalyzer()->analyzePath($f));

        $g = $this->tempPhpFile("try { f(); } catch (\\Exception \$e) {} // anylint-disable-line\n");
        $this->assertSame([], $this->newAnalyzer()->analyzePath($g));
    }

    public function testRuleListLeavesOtherRulesActive(): void
    {
        $f = $this->tempPhpFile(
            "// anylint-disable-next-line todo-tracker, hardcoded-secret -- навмисно\n"
            . self::EMPTY_CATCH,
        );
        $findings = $this->newAnalyzer()->analyzePath($f);

        $this->assertCount(1, $this->findingsFor($findings, 'empty-catch'));
    }

    public function testDisableFileSilencesTheRuleEverywhere(): void
    {
        $f = $this->tempPhpFile(
            "/* anylint-disable-file empty-catch */\n"
            . self::EMPTY_CATCH
            . "// TODO: лишається\n"
            . self::EMPTY_CATCH,
        );
        $findings = $this->newAnalyzer()->analyzePath($f);

        $this->assertSame([], $this->findingsFor($findings, 'empty-catch'));
        $this->assertCount(1, $this->findingsFor($findings, 'todo-tracker'));
    }

    public function testDirectiveWorksWithHashAndDashDashComments(): void
    {
        $py = $this->tempFile('py', "# anylint-disable-next-line todo-tracker\n# TODO: a\n");
        $this->assertSame([], $this->newAnalyzer()->analyzePath($py));

        $lua = $this->tempFile('lua', "-- anylint-disable-file\n-- TODO: a\n-- FIXME: b\n");
        $this->assertSame([], $this->newAnalyzer()->analyzePath($lua));
    }

    public function testMentionInsideAStringIsNotADirective(): void
    {
        $f = $this->tempPhpFile(
            "\$doc = 'use anylint-disable-file to silence';\n"
            . self::EMPTY_CATCH,
        );
        $this->assertCount(1, $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'empty-catch'));
    }

    public function testLegacyIgnoreMarkerStillWorksForEveryRule(): void
    {
        $f = $this->tempPhpFile('$t = "ghp_' . str_repeat('a', 36) . '"; // anylint:ignore' . "\n"); // anylint:ignore
        $this->assertSame([], $this->newAnalyzer()->analyzePath($f));
    }

    public function testParseErrorCanBeSilencedPerFile(): void
    {
        $f = $this->tempPhpFile("// anylint-disable-file parse-error\nfunction (\n");
        $this->assertSame([], $this->findingsFor($this->newAnalyzer()->analyzePath($f), 'parse-error'));
    }
}
