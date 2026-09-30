<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;
use SugarCraft\Diff\LineKind;

/**
 * Pure-engine pins ported from sugar-crush's Edit/Write suites
 * (tests/Tools/BuiltIn/EditTest.php, WriteTest.php) — the byte shapes those
 * tools asserted through `ToolResult::diff()` are asserted here directly
 * against the extracted engine, which is exactly what they pinned when the
 * code lived in `BuildsUnifiedDiff`.
 */
final class DiffTest extends TestCase
{
    public function testByteExactSingleLineChange(): void
    {
        $diff = Diff::compute('Hello World', 'Hello PHP');

        // Byte-exact and prefix-free: sugar-stash's DiffViewer::fromRawDiff()
        // consumes this verbatim, so a stray header line would break it.
        $expected = "--- a/file.txt\n"
            . "+++ b/file.txt\n"
            . "@@ -1,1 +1,1 @@\n"
            . "-Hello World\n"
            . "+Hello PHP\n";

        $this->assertSame($expected, $diff->unified('file.txt'));
    }

    public function testUnchangedContextLinesSurroundTheChange(): void
    {
        $before = "line1\nline2\nline3\nline4\nline5\n";
        $after = "line1\nline2\nLINE3-CHANGED\nline4\nline5\n";

        $content = Diff::compute($before, $after)->unified('f');

        $this->assertStringContainsString("@@ -1,5 +1,5 @@\n", $content);
        $this->assertStringContainsString(" line1\n", $content);
        $this->assertStringContainsString(" line2\n", $content);
        $this->assertStringContainsString("-line3\n", $content);
        $this->assertStringContainsString("+LINE3-CHANGED\n", $content);
        $this->assertStringContainsString(" line4\n", $content);
        $this->assertStringContainsString(" line5\n", $content);
    }

    public function testFarApartChangesBecomeSeparateHunks(): void
    {
        [$before, $after] = $this->targetPair([1, 20], [2, 18]);

        $content = Diff::compute($before, $after)->unified('f');

        // Each hunk header is "@@ -x,y +a,b @@" — two "@@" tokens per hunk —
        // so counting the leading "@@ -" marker gives the hunk count.
        $this->assertSame(2, substr_count($content, '@@ -'));
        $this->assertSame(2, substr_count($content, '-TARGET'));
        $this->assertSame(2, substr_count($content, '+REPLACED'));
    }

    public function testHunksSeparatedByExactlyContextWindowPlusOneMerge(): void
    {
        // Boundary regression for the merge threshold: two single-line
        // changes with exactly 6 unchanged lines between them (op-index
        // distance 2*CONTEXT_LINES+1 = 7) is the point at which real
        // `diff -u`/`git diff` still joins them into ONE hunk, since the two
        // context windows exactly touch.
        [$before, $after] = $this->targetPair([1, 12], [2, 9]);

        $content = Diff::compute($before, $after)->unified('f');

        $this->assertSame(1, substr_count($content, '@@ -'));
        $this->assertSame(2, substr_count($content, '-TARGET'));
        $this->assertSame(2, substr_count($content, '+REPLACED'));
    }

    public function testHunksSeparatedByOneMoreThanContextWindowSplit(): void
    {
        // One unchanged line further apart than the merge boundary above
        // (op-index distance 2*CONTEXT_LINES+2 = 8) stays two hunks: the
        // context windows no longer touch.
        [$before, $after] = $this->targetPair([1, 13], [2, 10]);

        $content = Diff::compute($before, $after)->unified('f');

        $this->assertSame(2, substr_count($content, '@@ -'));
        $this->assertSame(2, substr_count($content, '-TARGET'));
        $this->assertSame(2, substr_count($content, '+REPLACED'));
    }

    public function testDeletionEmptyingTheFileAnchorsTheNewSideAtZero(): void
    {
        // `diff -u` reports a start line of 0 (not a fabricated number) when
        // a side of the hunk is empty, since there is no line to anchor to.
        $content = Diff::compute("onlyline\n", '')->unified('f');

        $this->assertStringContainsString("@@ -1,1 +0,0 @@\n", $content);
        $this->assertStringNotContainsString('+1,0', $content);
    }

    public function testPureInsertionOnNewFileAnchorsTheOldSideAtZero(): void
    {
        // The Write-tool case: a new file is a diff whose old side is empty.
        $content = Diff::compute('', "alpha\nbeta\n")->unified('new.txt');

        $this->assertStringStartsWith("--- a/new.txt\n+++ b/new.txt\n", $content);
        $this->assertStringContainsString("@@ -0,0 +1,2 @@\n+alpha\n+beta\n", $content);
        $this->assertStringNotContainsString('-alpha', $content);
    }

    public function testMultiLineInsertionStaysMinimal(): void
    {
        $before = "start\nmiddle\nend\n";
        $after = "start\nmiddle\nextra1\nextra2\nend\n";

        $content = Diff::compute($before, $after)->unified('f');

        // "middle" itself is unchanged (common prefix) — a minimal diff shows
        // only the inserted lines, never a spurious delete/re-add of the line
        // the insertion happened after.
        $this->assertStringContainsString(' middle', $content);
        $this->assertStringNotContainsString('-middle', $content);
        $this->assertStringContainsString('+extra1', $content);
        $this->assertStringContainsString('+extra2', $content);
    }

    // =========================================================================
    // Edges the tool-level suites could not reach from the outside
    // =========================================================================

    public function testBothSidesEmptyIsAnEmptyDiff(): void
    {
        $diff = Diff::compute('', '');

        $this->assertTrue($diff->isEmpty());
        $this->assertSame('', $diff->unified('f'));
        $this->assertSame('', $diff->hunkText());
        $this->assertSame([], $diff->hunks);
        $this->assertSame([], $diff->lines);
        $this->assertSame(0, $diff->addedLines());
        $this->assertSame(0, $diff->removedLines());
    }

    public function testIdenticalInputProducesNoDiff(): void
    {
        $text = "one\ntwo\nthree\n";

        $this->assertTrue(Diff::compute($text, $text)->isEmpty());
        $this->assertSame('', Diff::compute($text, $text)->unified('f'));
    }

    public function testTrailingNewlineTerminatesAndDoesNotAddAnEmptyLine(): void
    {
        // git's counting rule: "a\n" and "a\n\n" differ — the latter ends
        // with a genuine empty line.
        $diff = Diff::compute("a\n", "a\n\n");

        $this->assertFalse($diff->isEmpty());
        $this->assertSame(1, $diff->addedLines());
        $this->assertStringContainsString("+\n", $diff->unified('f'));
    }

    public function testLosingOnlyTheFinalNewlineIsInvisibleToTheLineEngine(): void
    {
        // The ported splitLines semantics count CONTENT lines; a side that
        // merely loses its final newline diffs empty. The writer's
        // no-newline marker only annotates rows inside real hunks — this
        // documented divergence from GNU (which treats the terminator as
        // part of the line) is inherited from the source engine.
        $this->assertTrue(Diff::compute("x\n", 'x')->isEmpty());
    }

    public function testUnicodeLinesRoundTripByteIdentically(): void
    {
        $before = "héllo wörld\n日本語のテキスト\nemoji: 🎂 cake\n";
        $after = "héllo wörld\n日本語テキスト\nemoji: 🎂 cake\n";

        $content = Diff::compute($before, $after)->unified('f');

        $this->assertStringContainsString("-日本語のテキスト\n", $content);
        $this->assertStringContainsString("+日本語テキスト\n", $content);
        $this->assertStringContainsString(" emoji: 🎂 cake\n", $content);
    }

    public function testListInputIsReadAsPreSplitLines(): void
    {
        $diff = Diff::compute(['a', 'b'], ['a', 'c']);

        $this->assertFalse($diff->isEmpty());
        $this->assertSame("@@ -1,2 +1,2 @@\n a\n-b\n+c\n", $diff->hunkText());
        // Array sides are each read as newline-terminated.
        $this->assertTrue($diff->beforeHasFinalNewline);
        $this->assertTrue($diff->afterHasFinalNewline);
    }

    public function testAssociativeArraySideFailsLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('list of strings');

        Diff::compute(['k' => 'a'], ['a']);
    }

    public function testNonStringLineSideFailsLoud(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only strings');

        /** @psalm-suppress InvalidArgument intentionally wrong element type */
        Diff::compute([1, 2], [1, 3]);
    }

    public function testMaxLcsCellsFallbackEmitsNonMinimalButValidBlock(): void
    {
        $before = "k1\nT1\nk3\nk4\nT2\nk5\n";
        $after = "k1\nR1\nk3\nk4\nR2\nk5\n";

        // With the table budget below the middle region (4*4 = 16 cells), the
        // engine deletes and re-inserts the whole middle instead of keeping
        // k3/k4 as context.
        $capped = Diff::compute($before, $after, DiffOptions::new()->withMaxLcsCells(9));
        $this->assertSame(0, substr_count($capped->unified('f'), ' k3'));
        $this->assertSame(1, substr_count($capped->unified('f'), '@@ -'));

        // The default budget keeps the minimal shape.
        $full = Diff::compute($before, $after);
        $this->assertSame(1, substr_count($full->unified('f'), ' k3'));
    }

    public function testLinesCarryPerSideLineNumbers(): void
    {
        $diff = Diff::compute("a\nb\nc\n", "a\nx\nc\n");

        $kinds = array_map(static fn ($l) => $l->kind->value, $diff->lines);
        $this->assertSame(['eq', 'del', 'ins', 'eq'], $kinds);

        $this->assertSame(1, $diff->lines[0]->oldNumber);
        $this->assertSame(1, $diff->lines[0]->newNumber);
        $this->assertSame(2, $diff->lines[1]->oldNumber);
        $this->assertNull($diff->lines[1]->newNumber, 'a removal has no new-side line');
        $this->assertNull($diff->lines[2]->oldNumber, 'an addition has no old-side line');
        $this->assertSame(2, $diff->lines[2]->newNumber);
        $this->assertSame(3, $diff->lines[3]->oldNumber);
        $this->assertSame(3, $diff->lines[3]->newNumber);
    }

    public function testAddedAndRemovedCounts(): void
    {
        $diff = Diff::compute("a\nb\nc\n", "a\nx\ny\nc\n");

        $this->assertSame(2, $diff->addedLines());
        $this->assertSame(1, $diff->removedLines());
        $this->assertSame(2, $diff->hunks[0]->addedCount());
        $this->assertSame(1, $diff->hunks[0]->removedCount());
    }

    public function testIgnoreWhitespaceFoldsComparisonOnly(): void
    {
        $options = DiffOptions::new()->withIgnoreWhitespace();

        $this->assertTrue(Diff::compute("a b\tc", 'a  b   c', $options)->isEmpty());
        // Without the option the same pair is a change.
        $this->assertFalse(Diff::compute('a b', 'a  b')->isEmpty());

        // Emitted context text keeps the OLD side's bytes, never the fold.
        $diff = Diff::compute("x = 1;\ny\n", "x  =  1;\nz\n", $options);
        $this->assertStringContainsString(" x = 1;\n", $diff->unified('f'));
    }

    public function testIgnoreCaseFoldsComparisonAndKeepsOldBytes(): void
    {
        $options = DiffOptions::new()->withIgnoreCase();

        $this->assertTrue(Diff::compute('Hello', 'hello', $options)->isEmpty());

        $diff = Diff::compute("A\nB", "a\nC", $options);
        // The context row carries the old side's 'A', never the folded 'a'.
        $this->assertSame("@@ -1,2 +1,2 @@\n A\n-B\n+C\n", $diff->hunkText());
    }

    public function testZeroContextWindowEmitsChangeOnlyHunks(): void
    {
        $before = "a\nb\nc\nd\ne\nf\ng\n";
        $after = "a\nb\nc\nX\ne\nf\ng\n";

        $diff = Diff::compute($before, $after, DiffOptions::new()->withContextLines(0));

        $this->assertSame("@@ -4,1 +4,1 @@\n-d\n+X\n", $diff->hunkText());
    }

    /** @return array{0:string,1:string} */
    private function targetPair(array $range, array $targets): array
    {
        $lines = [];
        for ($i = $range[0]; $i <= $range[1]; $i++) {
            $lines[] = in_array($i, $targets, true) ? 'TARGET' : "line$i";
        }
        $before = implode("\n", $lines) . "\n";

        return [$before, str_replace('TARGET', 'REPLACED', $before)];
    }
}
