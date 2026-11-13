<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Diff;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Diff\DiffWriter;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * @covers \SugarCraft\Vcr\Diff\DiffWriter
 */
final class DiffWriterTest extends TestCase
{
    private DiffWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new DiffWriter();
    }

    public function testWriteUnifiedDiffCreatesFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'diff-test-');
        $this->assertNotFalse($path);

        $expected = "hello\nworld\n";
        $actual = "hello\nworld\n";

        $result = $this->writer->writeUnifiedDiff($path, $expected, $actual);

        $this->assertNotFalse($result);
        $content = Stream::read($path);
        $this->assertStringContainsString('---', $content);
        $this->assertStringContainsString('+++', $content);
        unlink($path);
    }

    public function testUnifiedDiffShowsChanges(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'diff-test-');
        $this->assertNotFalse($path);

        $expected = "hello\nworld\n";
        $actual = "hello\nuniverse\n";

        $this->writer->writeUnifiedDiff($path, $expected, $actual);

        $content = Stream::read($path);
        $this->assertStringContainsString('-world', $content);
        $this->assertStringContainsString('+universe', $content);
        unlink($path);
    }

    public function testUnifiedDiffIdenticalFiles(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'diff-test-');
        $this->assertNotFalse($path);

        $content = "hello\nworld\n";
        $result = $this->writer->writeUnifiedDiff($path, $content, $content);

        $this->assertNotFalse($result);
        $output = Stream::read($path);
        $this->assertStringContainsString('(no differences)', $output);
        unlink($path);
    }

    public function testBuildUnifiedDiffReturnsString(): void
    {
        $diff = $this->writer->buildUnifiedDiff("a\nb\n", "a\nc\n");
        $this->assertIsString($diff);
        $this->assertStringContainsString('---', $diff);
        $this->assertStringContainsString('+++', $diff);
    }

    public function testBuildUnifiedDiffWithMultipleChanges(): void
    {
        $expected = "line1\nline2\nline3\nline4\nline5\n";
        $actual = "line1\nmodified2\nline3\nmodified4\nline5\n";

        $diff = $this->writer->buildUnifiedDiff($expected, $actual);

        $this->assertStringContainsString('-line2', $diff);
        $this->assertStringContainsString('+modified2', $diff);
        $this->assertStringContainsString('-line4', $diff);
        $this->assertStringContainsString('+modified4', $diff);
    }

    public function testBuildUnifiedDiffNormalizesLineEndings(): void
    {
        $expected = "line1\r\nline2\r\n";
        $actual = "line1\nline2\n";

        $diff = $this->writer->buildUnifiedDiff($expected, $actual);

        // Should not report differences due to line ending normalization
        $this->assertStringContainsString('(no differences)', $diff);
    }

    public function testWriteUnifiedDiffReturnsFalseOnFailure(): void
    {
        // Try to write to an invalid path
        $result = $this->writer->writeUnifiedDiff('/nonexistent/directory/file.diff', 'a', 'b');
        $this->assertFalse($result);
    }

    public function testBuildUnifiedDiffEmptyFiles(): void
    {
        $diff = $this->writer->buildUnifiedDiff('', '');
        $this->assertStringContainsString('(no differences)', $diff);
    }

    public function testBuildUnifiedDiffSingleLineChange(): void
    {
        $diff = $this->writer->buildUnifiedDiff("old\n", "new\n");
        $this->assertStringContainsString('-old', $diff);
        $this->assertStringContainsString('+new', $diff);
    }

    public function testBuildUnifiedDiffAddedLines(): void
    {
        $expected = "line1\n";
        $actual = "line1\nline2\nline3\n";

        $diff = $this->writer->buildUnifiedDiff($expected, $actual);

        $this->assertStringContainsString('+line2', $diff);
        $this->assertStringContainsString('+line3', $diff);
    }

    public function testBuildUnifiedDiffRemovedLines(): void
    {
        $expected = "line1\nline2\nline3\n";
        $actual = "line1\n";

        $diff = $this->writer->buildUnifiedDiff($expected, $actual);

        $this->assertStringContainsString('-line2', $diff);
        $this->assertStringContainsString('-line3', $diff);
    }

    public function testWriteAnsiDiffDoesNotThrow(): void
    {
        $expected = "hello\nworld\n";
        $actual = "hello\nuniverse\n";

        // Should not throw, just write to output
        $this->writer->writeAnsiDiff($expected, $actual, Stream::memory('w'));
        // Reaching this line without an exception is the assertion.
        $this->addToAssertionCount(1);
    }

    public function testBuildUnifiedDiffWithBinaryContent(): void
    {
        $expected = "\x00\x01\x02\n";
        $actual = "\x00\x01\x03\n";

        $diff = $this->writer->buildUnifiedDiff($expected, $actual);

        // Binary content should be escaped (null byte becomes \0 in output)
        $this->assertStringContainsString('\\0', $diff);
        $this->assertStringContainsString('-', $diff);
        $this->assertStringContainsString('+', $diff);
    }

    public function testDifferingRowsCarryTheBangTextCueInBothSections(): void
    {
        $text = $this->ansiDiffPlain("alpha\nbravo\n", "alpha\ncharlie\n");

        // Differing rows carry '!' in both the EXPECTED and ACTUAL blocks;
        // the same row carries the space cue — never '!'.
        self::assertMatchesRegularExpression('/^! bravo/m', $text);
        self::assertMatchesRegularExpression('/^! charlie/m', $text);
        self::assertMatchesRegularExpression('/^  alpha/m', $text);
        self::assertLessThan(2, preg_match_all('/^! alpha/m', $text), 'same line must never carry the bang cue');
    }

    public function testAnsiDiffLabelsAndRulesAreLegibleWithoutColor(): void
    {
        $text = $this->ansiDiffPlain("hello\n", "world\n");

        // Plain dash rules, not box-drawing chrome: the labels survive an
        // SGR strip and a non-Unicode-safe terminal alike.
        self::assertMatchesRegularExpression('/^--- EXPECTED -/m', $text);
        self::assertMatchesRegularExpression('/^--- ACTUAL -/m', $text);
    }

    public function testAnsiDiffEmitsNoBoxDrawingGlyphs(): void
    {
        $stream = Stream::memory('w+');
        $this->writer->writeAnsiDiff('a', 'b', $stream);
        rewind($stream);
        $raw = stream_get_contents($stream) ?: '';

        foreach (['┌', '┐', '└', '┘', '│', '─'] as $glyph) {
            self::assertStringNotContainsString($glyph, $raw, "box glyph {$glyph} must be gone from the diff chrome");
        }
    }

    // ------------------------------------------------------------------
    // VCR-S22 · real diff algorithm + honest report bytes (findings 027, 106, 114)
    // ------------------------------------------------------------------

    public function testOnePrependedLineMarksExactlyOneHunk(): void
    {
        $expected = implode("\n", ['l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7', 'l8']) . "\n";
        $actual = "NEW\n" . $expected;

        $diff = $this->writer->buildUnifiedDiff($expected, $actual);

        // The finding's acceptance: a prepend is ONE region naming the
        // inserted line — the retired positional walker marked every
        // later line "changed" and bloated this into a full re-print.
        self::assertSame(1, preg_match_all('/^@@/m', $diff));
        // Counting one hunk is not enough: the header must carry GNU's
        // 1-based start (three context rows kept, insertion never deletes).
        self::assertStringContainsString(
            '@@ -1,3 +1,4 @@',
            $diff,
            'the single hunk must be named like GNU diff -u names it',
        );
        self::assertStringContainsString("+NEW\n l1\n", $diff);
        self::assertSame(
            0,
            preg_match_all('/^-(?!-)/m', $diff),
            'nothing was deleted, so the hunks must carry no removal rows',
        );
    }

    public function testAdjacentChangesMergeIntoOneRegionAndContextPrintsOnce(): void
    {
        $lines = array_map(static fn (int $i): string => "line $i", range(1, 20));
        $expected = implode("\n", $lines) . "\n";

        $far = $lines;
        $far[1] = 'changed 2';
        $far[11] = 'changed 12';
        self::assertSame(
            2,
            preg_match_all('/^@@/m', $this->writer->buildUnifiedDiff($expected, implode("\n", $far) . "\n")),
            'changes more than 2x context apart stay separate hunks',
        );

        $near = $lines;
        $near[1] = 'changed 2';
        $near[2] = 'changed 3';
        $diff = $this->writer->buildUnifiedDiff($expected, implode("\n", $near) . "\n");
        self::assertSame(
            1,
            preg_match_all('/^@@/m', $diff),
            'two edits a line apart are one region (finding 027: the old layout reprinted context per change)',
        );

        // No context row repeats inside the single hunk (finding lines
        // excluded; blank ' ' context lines are structural, not content).
        $content = [];
        foreach (explode("\n", $diff) as $row) {
            if (strlen($row) > 1 && $row[0] === ' ' && !isset($content[$row])) {
                $content[$row] = true;
            } elseif (strlen($row) > 1 && $row[0] === ' ') {
                self::fail("context row repeated within one hunk: {$row}");
            }
        }
        self::assertArrayHasKey(' line 1', $content);
    }

    public function testUnifiedDiffAppliesWithGnuPatch(): void
    {
        $patch = trim((string) @shell_exec('command -v patch 2>/dev/null'));
        if ($patch === '') {
            self::markTestSkipped('GNU patch not installed on this host');
        }

        // A drift spread exercising all three op kinds: a mid change, a
        // deletion block, and a tail append — the shapes whose @@ headers
        // the retired positional generator got wrong (finding 027: the
        // compat claim was printed but unproven).
        $expected = implode("\n", array_map(
            static fn (int $i): string => "row {$i}",
            range(1, 20),
        )) . "\n";
        $actual = implode("\n", array_merge(
            ['row 1', 'ROW 2 EDITED', 'row 3', 'row 4', 'row 5', 'row 6', 'row 7', 'row 8'],
            ['row 9', 'row 10', 'row 11', 'row 14', 'row 15', 'row 16', 'row 17', 'row 18'],
            ['row 19', 'row 20', 'row 21 appended', 'row 22 appended'],
        )) . "\n";

        $dir = self::scratchDir();
        file_put_contents("{$dir}/expected.txt", $expected);
        file_put_contents("{$dir}/actual.txt", $actual);
        file_put_contents("{$dir}/drift.diff", $this->writer->buildUnifiedDiff($expected, $actual));

        // patch alone is lenient about hunk line numbers, so pair it with
        // the strictest claim on the finding: our @@ headers must be
        // byte-identical to what GNU diff -u itself prints for the same pair.
        $diffTool = trim((string) @shell_exec('command -v diff 2>/dev/null'));
        if ($diffTool === '') {
            self::markTestSkipped('GNU diff not installed on this host');
        }
        $gnuHeaders = array_values(array_filter(
            explode("\n", (string) shell_exec(sprintf(
                'cd %1$s && %2$s -u expected.txt actual.txt 2>/dev/null',
                escapeshellarg($dir),
                escapeshellarg($diffTool),
            ))),
            static fn (string $line): bool => str_starts_with($line, '@@'),
        ));
        $ourHeaders = array_values(array_filter(
            explode("\n", (string) file_get_contents("{$dir}/drift.diff")),
            static fn (string $line): bool => str_starts_with($line, '@@'),
        ));
        self::assertSame(
            $gnuHeaders,
            $ourHeaders,
            'the @@ hunk headers must match GNU diff byte for byte',
        );

        exec(
            sprintf('cd %s && patch --silent --fuzz=0 expected.txt drift.diff 2>&1', escapeshellarg($dir)),
            $output,
            $status,
        );
        self::assertSame(0, $status, 'GNU patch must apply the generated diff: ' . implode("\n", $output));
        self::assertSame($actual, file_get_contents("{$dir}/expected.txt"));
    }

    public function testWrittenDiffCarriesNoRawControlBytesFromThePayload(): void
    {
        // The cassette side of the comparison is recorded terminal bytes:
        // an ESC in them used to ship straight into the .diff and fire in
        // the operator's pager (finding 106).
        $expected = "prompt\n" . "\x1b[2J\x07done\x9bmore\xC2\x85tail\nlast\n";
        $actual = "prompt\n\x1b[31mred\x1b[0m\nlast\n";

        $path = tempnam(sys_get_temp_dir(), 'diff-clean-');
        $this->assertNotFalse($path);
        $written = $this->writer->writeUnifiedDiff($path, $expected, $actual);
        $this->assertNotFalse($written);
        self::assertSame($written, filesize($path), 'the return shape is the documented byte count');

        $content = (string) file_get_contents($path);
        unlink($path);

        foreach (["\x1b", "\x07", "\x9b", "\xC2\x85"] as $raw) {
            self::assertStringNotContainsString($raw, $content, 'payload control bytes must not survive raw');
        }
        self::assertStringContainsString('\\e[2J\\adone\\x9Bmore\\x85tail', $content);
    }

    public function testAnsiReportRowsNeverEmitThePayloadAsLiveBytes(): void
    {
        $expected = "ok\n" . "\x1b[2Jpwn\x07" . "\nend\n";
        $stream = Stream::memory('w+');
        $this->writer->writeAnsiDiff($expected, "ok\nplain\nend\n", $stream);
        rewind($stream);
        $raw = (string) stream_get_contents($stream);

        // The report's own chrome is SGR; the payload substring must not
        // be present as live bytes anywhere.
        self::assertStringNotContainsString("\x1b[2Jpwn\x07", $raw);

        $plain = $this->ansiDiffPlain($expected, "ok\nplain\nend\n");
        self::assertStringContainsString('\\e[2Jpwn\\a', $plain, 'escaped form is what the operator reads');
    }

    public function testValidUtf8PayloadSurvivesIntactWithoutContinuationByteMangling(): void
    {
        // A blind 0x7f-0x9f byte class would escape the continuation bytes
        // inside every CJK character (日 = E6 97 A5 — 0x97 in band) and
        // mangle the payload the report exists to show.
        $expected = "héllo 日本語\nplain\n";
        $diff = $this->writer->buildUnifiedDiff($expected, "héllo 日本語\nchanged\n");

        self::assertStringContainsString('héllo 日本語', $diff);
        self::assertStringNotContainsString('\\x', $diff, 'printable UTF-8 needs no escapes at all');
    }

    public function testMinifiedLineIsCappedAndEllipsizedWithUniformRows(): void
    {
        // finding 114: one 10 KB minified line must not pad every row to
        // 10 KB — the field caps at MAX_ROW_WIDTH and the cut is marked.
        $minified = str_repeat('m', 10000);
        $plain = $this->ansiDiffPlain("short\n", $minified . "\ntail\n");

        $rows = array_filter(
            explode("\n", $plain),
            static fn (string $r): bool => $r !== '',
        );
        $lengths = array_values(array_unique(array_map('strlen', $rows)));
        self::assertSame(
            [DiffWriter::MAX_ROW_WIDTH + 2],
            $lengths,
            'every report row lands on exactly fieldWidth + 2 columns',
        );
        self::assertStringContainsString('…', $plain, 'the cut must be marked, not silent');
    }

    public function testReportRowCapPrintsOverflowFooter(): void
    {
        $lines = array_map(static fn (int $i): string => "l{$i}", range(1, 410));
        $expected = implode("\n", $lines) . "\n";
        $lines[409] = 'l409 changed past the cap';
        $actual = implode("\n", $lines) . "\n";

        $plain = $this->ansiDiffPlain($expected, $actual);

        self::assertSame(2, substr_count($plain, "… +10 lines"), 'one footer per side');
        self::assertStringNotContainsString('l409', $plain, 'rows past the cap are not printed');
        $body = array_filter(
            explode("\n", $plain),
            static fn (string $r): bool => $r !== '' && !str_starts_with($r, '---') && !str_starts_with($r, '  …'),
        );
        self::assertLessThanOrEqual(800, count($body), 'both sides together stay under 2x the row cap');
    }

    public function testContextWidthIsHonouredEndToEnd(): void
    {
        $lines = array_map(static fn (int $i): string => "line {$i}", range(1, 20));
        $expected = implode("\n", $lines) . "\n";
        $changed = $lines;
        $changed[9] = 'line 9 EDITED';
        $diff = $this->writer->buildUnifiedDiff($expected, implode("\n", $changed) . "\n", 1);

        $contextRows = array_filter(
            explode("\n", $diff),
            static fn (string $r): bool => str_starts_with($r, ' line '),
        );
        self::assertCount(2, $contextRows, 'context 1 prints exactly the named surround, not a hardcoded 3');
    }

    /**
     * Run writeAnsiDiff and return its output with every SGR sequence
     * removed — what a NO_COLOR pipe or colour-blind reader effectively
     * sees, so pins on the returned shape test the text cues alone.
     */
    private function ansiDiffPlain(string $expected, string $actual): string
    {
        $stream = Stream::memory('w+');
        $this->writer->writeAnsiDiff($expected, $actual, $stream);
        rewind($stream);
        $raw = stream_get_contents($stream) ?: '';

        return preg_replace('/\x1b\[[0-9;]*m/', '', $raw) ?? $raw;
    }

    private function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/diffwriter-' . bin2hex(random_bytes(6));
        self::assertNotFalse(mkdir($dir, 0700, true));

        return $dir;
    }
}
