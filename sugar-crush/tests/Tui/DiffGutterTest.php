<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Tui\DiffGutter;

/**
 * crush_code.md Phase 8 item 1: the diff viewer had no line-number column, so a
 * reviewer could see what changed but not where. These pin the numbering rules
 * (`diff -u`'s own counting) and the fixed-width invariant the renderer's
 * column clipping depends on.
 */
final class DiffGutterTest extends TestCase
{
    /** @param list<string> $lines @return list<string> */
    private function prefixes(array $lines): array
    {
        return DiffGutter::forDiff($lines)->prefixes;
    }

    public function testContextAdvancesBothSidesAndAdditionsOnlyAdvanceTheNewSide(): void
    {
        $lines = [
            '--- a/src/App.php',
            '+++ b/src/App.php',
            '@@ -1,3 +1,3 @@',
            ' <?php',
            '-$old = 1;',
            '+$new = 2;',
        ];

        $this->assertSame(
            ['   │ ', '   │ ', '   │ ', '1 1│ ', '2  │ ', '  2│ '],
            $this->prefixes($lines),
        );
    }

    /**
     * The counting rule that actually matters: a run of removals must not
     * advance the new-file counter, and vice versa, or every number after the
     * first hunk with an uneven edit is wrong.
     */
    public function testUnevenHunkKeepsTheTwoCountersIndependent(): void
    {
        $lines = [
            '@@ -10,4 +10,3 @@',
            ' keep',
            '-drop one',
            '-drop two',
            '+add one',
            ' tail',
        ];

        $this->assertSame(
            [
                [null, null],
                [10, 10],
                [11, null],
                [12, null],
                [null, 11],
                [13, 12],
            ],
            array_map(
                static fn (string $p): array => array_map(
                    static fn (string $c): ?int => trim($c) === '' ? null : (int) trim($c),
                    [substr($p, 0, 2), substr($p, 3, 2)],
                ),
                $this->prefixes($lines),
            ),
        );
    }

    /**
     * A second file's `--- `/`+++ ` header inside one diff starts with the same
     * bytes as a removal and an addition. Counting it as such would desync
     * every number below it, so the walk resets there.
     */
    public function testFileHeadersMidDiffResetTheWalkInsteadOfCountingAsEdits(): void
    {
        $lines = [
            '@@ -1,1 +1,1 @@',
            '-a',
            '+b',
            'diff --git a/two.php b/two.php',
            '--- a/two.php',
            '+++ b/two.php',
            '@@ -5,1 +5,1 @@',
            '-c',
            '+d',
        ];

        $prefixes = $this->prefixes($lines);

        // The three header rows carry no numbers at all...
        foreach ([3, 4, 5] as $i) {
            $this->assertSame('', trim(str_replace('│', '', $prefixes[$i])), "row {$i}");
        }
        // ...and the second hunk restarts from its own header, not from 2.
        $this->assertStringStartsWith('5', ltrim($prefixes[7]));
        $this->assertSame('  5│ ', $prefixes[8]);
    }

    /** "\ No newline at end of file" annotates the row above; it is not a line. */
    public function testNoNewlineMarkerConsumesNoLineNumber(): void
    {
        $prefixes = $this->prefixes(['@@ -1,1 +1,1 @@', '-a', '\\ No newline at end of file', '+b']);

        $this->assertSame('  1│ ', $prefixes[3], 'the addition is still new-file line 1');
        $this->assertSame('   │ ', $prefixes[2]);
    }

    /** Rows before the first hunk header have nothing to point at. */
    public function testRowsBeforeTheFirstHunkHeaderAreUnnumbered(): void
    {
        $prefixes = $this->prefixes(['--- a/x', '+++ b/x', '@@ -1,1 +1,1 @@', ' ok']);

        $this->assertSame(['   │ ', '   │ ', '   │ '], array_slice($prefixes, 0, 3));
    }

    /**
     * Every prefix must be the same display width - the renderer subtracts
     * DiffGutter::$width once and truncates every body to what's left, so a
     * ragged gutter would silently push rows past the viewport edge.
     */
    public function testEveryPrefixIsExactlyTheAdvertisedDisplayWidth(): void
    {
        $lines = ['--- a/x', '+++ b/x', '@@ -998,3 +998,3 @@'];
        for ($i = 0; $i < 5; $i++) {
            $lines[] = '+line';
        }

        $gutter = DiffGutter::forDiff($lines);

        $this->assertSame(4 * 2 + 1 + 2, $gutter->width, 'four-digit numbers');
        foreach ($gutter->prefixes as $prefix) {
            $this->assertSame($gutter->width, Width::string($prefix));
        }
        $this->assertSame($gutter->width, Width::string($gutter->blank));
    }

    /** A diff with no hunk header is unnumberable; don't steal columns for it. */
    public function testUnnumberableDiffProducesAZeroWidthGutter(): void
    {
        $gutter = DiffGutter::forDiff(['--- a/x', '+++ b/x']);

        $this->assertSame(0, $gutter->width);
        $this->assertSame(['', ''], $gutter->prefixes);
        $this->assertSame('', $gutter->blank);
    }

    public function testNoneIsAZeroWidthGutterOfTheRequestedLength(): void
    {
        $gutter = DiffGutter::none(3);

        $this->assertSame(0, $gutter->width);
        $this->assertSame(['', '', ''], $gutter->prefixes);
        $this->assertSame('', $gutter->blank);
    }

    public function testEmptyDiffProducesNoPrefixes(): void
    {
        $this->assertSame([], DiffGutter::forDiff([])->prefixes);
    }

    /**
     * A hunk header whose start line does not fit in an int used to be fatal:
     * `(int)` clamps the literal to PHP_INT_MAX, the first `++` promotes the
     * counter to float, and format()'s `?int` parameter rejects it with a
     * TypeError -- thrown from renderDiff(), which runs inside Chat::view(),
     * i.e. it would kill the Program with the terminal still in raw mode.
     */
    public function testAnOversizedHunkHeaderDoesNotPromoteTheCounterToFloat(): void
    {
        $lines = ['@@ -99999999999999999999,3 +99999999999999999999,3 @@', ' ctx', '-old'];

        $gutter = DiffGutter::forDiff($lines);

        // Recognised as a hunk, but declining to number beats printing a
        // clamped -- i.e. wrong -- line number.
        $this->assertSame(0, $gutter->width);
        $this->assertSame(['', '', ''], $gutter->prefixes);
    }

    /**
     * The header is still RECOGNISED even when it is not numberable, so a
     * previous hunk's counters cannot bleed through the rows below it.
     */
    public function testAnOversizedHunkHeaderStopsThePreviousHunksNumbering(): void
    {
        $prefixes = $this->prefixes([
            '@@ -1,2 +1,2 @@',
            ' first',
            '@@ -99999999999999999999,3 +99999999999999999999,3 @@',
            ' second',
            ' third',
        ]);

        $this->assertSame('1 1│ ', $prefixes[1]);
        foreach ([2, 3, 4] as $i) {
            $this->assertSame('   │ ', $prefixes[$i], "row {$i} must not keep counting");
        }
    }

    /** A nine-digit start line is the largest still numbered. */
    public function testTheLargestNumberableHunkStartIsStillNumbered(): void
    {
        $prefixes = $this->prefixes(['@@ -999999999,1 +999999999,1 @@', ' ctx']);

        $this->assertSame('999999999 999999999│ ', $prefixes[1]);
    }

    /**
     * `--` is the line-comment token in SQL, Lua, Haskell and Ada, so deleting
     * `-- users table, legacy` emits the row `--- users table, legacy`, which
     * reads byte-for-byte like a file header. Treating it as one closed the
     * hunk and every remaining row lost its numbers -- number loss, which is
     * the gutter failing at its only job.
     */
    public function testDeletedCommentRowsAreNotMistakenForFileHeaders(): void
    {
        $prefixes = $this->prefixes([
            '@@ -10,4 +10,4 @@',
            ' CREATE TABLE users (',
            '--- users table, legacy',
            ' id INT',
            ' );',
        ]);

        $this->assertSame('10 10│ ', $prefixes[1]);
        $this->assertSame('11   │ ', $prefixes[2], 'a removal, not a header');
        $this->assertSame('12 11│ ', $prefixes[3], 'the rest of the hunk keeps its numbers');
        $this->assertSame('13 12│ ', $prefixes[4]);
    }

    /** The twin case: an added line that itself starts `++ `. */
    public function testAddedRowsStartingWithPlusPlusAreNotMistakenForFileHeaders(): void
    {
        $prefixes = $this->prefixes([
            '@@ -10,2 +10,3 @@',
            ' for (;;) {',
            '+++ i is the idiom',
            ' }',
        ]);

        $this->assertSame('   11│ ', $prefixes[2], 'an addition, not a header');
        $this->assertSame('11 12│ ', $prefixes[3]);
    }

    /**
     * The content reading is only safe because git always emits
     * `diff --git `/`index ` ahead of a real `--- `, and those close the hunk
     * unconditionally. Numbering across a file boundary must still restart.
     */
    public function testMultiFileNumberingStillRestartsPerFile(): void
    {
        $prefixes = $this->prefixes([
            'diff --git a/one b/one',
            'index 111..222 100644',
            '--- a/one',
            '+++ b/one',
            '@@ -10,2 +10,2 @@',
            ' ctx',
            ' more',
            'diff --git a/two b/two',
            'index 333..444 100644',
            '--- a/two',
            '+++ b/two',
            '@@ -100,2 +100,2 @@',
            ' ctx',
            ' more',
        ]);

        $this->assertSame(' 10  10│ ', $prefixes[5]);
        $this->assertSame(' 11  11│ ', $prefixes[6]);
        $this->assertSame('100 100│ ', $prefixes[12]);
        $this->assertSame('101 101│ ', $prefixes[13]);
        foreach ([7, 8, 9, 10] as $i) {
            $this->assertSame('       │ ', $prefixes[$i], "row {$i} is a header");
        }
    }

    /**
     * fileHeaders() exists so Renderer::styleDiffLine() colours a row from the
     * same verdict this class numbers it by, instead of re-deciding the
     * `--- ` question with no idea whether a hunk is open.
     */
    public function testFileHeadersReportsTheSameVerdictTheNumberingUsed(): void
    {
        $lines = [
            'diff --git a/schema.sql b/schema.sql',
            '--- a/schema.sql',
            '+++ b/schema.sql',
            '@@ -10,3 +10,3 @@',
            ' CREATE TABLE users (',
            '--- users table, legacy',
            '+++ i is the idiom',
        ];

        $this->assertSame(
            [true, true, true, false, false, false, false],
            DiffGutter::fileHeaders($lines),
        );
    }

    /** One flag per input row, so the renderer can index it by row. */
    public function testFileHeadersIsOneFlagPerRow(): void
    {
        $lines = ['@@ -1,2 +1,2 @@', ' a', '-b', '+c'];

        $this->assertCount(count($lines), DiffGutter::fileHeaders($lines));
        $this->assertSame([], DiffGutter::fileHeaders([]));
    }

    /**
     * The gutter's advertised width is what the renderer subtracts from the
     * body budget, so it has to be measured with the same Width the renderer
     * truncates by -- not with mb_strlen, which agrees for today's separator
     * but would not for a wide or combining one.
     */
    public function testTheAdvertisedWidthIsMeasuredWithWidthNotCharacterCount(): void
    {
        $gutter = DiffGutter::forDiff(['@@ -1,1 +1,1 @@', ' ctx']);

        foreach ($gutter->prefixes as $prefix) {
            $this->assertSame($gutter->width, Width::string($prefix));
        }
        $this->assertSame(Width::string($gutter->prefixes[1]), $gutter->width);
    }
}
