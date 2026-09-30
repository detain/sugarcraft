<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests\Reader;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\Reader\ScannedLine;
use SugarCraft\Diff\Reader\UnifiedScan;

/**
 * Region-model pins ported from sugar-crush's tests/Tui/DiffGutterTest.php —
 * the counting rules (not the gutter's fixed-width formatting, which is view
 * code that stays downstream) asserted straight against the scan model the
 * gutter was built on.
 *
 */
final class UnifiedScanTest extends TestCase
{
    /** @param string|list<string> $rows @return list<array{0:?int,1:?int}> */
    private function numbers(string|array $rows): array
    {
        return array_map(
            static fn (ScannedLine $l): array => [$l->old, $l->new],
            UnifiedScan::lines($rows),
        );
    }

    public function testContextAdvancesBothSidesAndEditsAdvanceOnlyTheirOwn(): void
    {
        $this->assertSame(
            [[null, null], [null, null], [null, null], [1, 1], [2, null], [null, 2]],
            $this->numbers([
                '--- a/src/App.php',
                '+++ b/src/App.php',
                '@@ -1,3 +1,3 @@',
                ' <?php',
                '-$old = 1;',
                '+$new = 2;',
            ]),
        );
    }

    public function testUnevenHunkKeepsTheTwoCountersIndependent(): void
    {
        // The rule that matters: a run of removals must not advance the
        // new-file counter, and vice versa, or every number after the first
        // uneven hunk is wrong.
        $this->assertSame(
            [[null, null], [10, 10], [11, null], [12, null], [null, 11], [13, 12]],
            $this->numbers([
                '@@ -10,4 +10,3 @@',
                ' keep',
                '-drop one',
                '-drop two',
                '+add one',
                ' tail',
            ]),
        );
    }

    public function testFileHeadersMidDiffResetTheWalkInsteadOfCountingAsEdits(): void
    {
        $rows = [
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

        $numbers = $this->numbers($rows);

        $this->assertSame([null, null], $numbers[3]);
        $this->assertSame([null, null], $numbers[4]);
        $this->assertSame([null, null], $numbers[5]);
        // The second hunk restarts from its own header, not from 2.
        $this->assertSame([5, null], $numbers[7]);
        $this->assertSame([null, 5], $numbers[8]);
    }

    public function testNoNewlineMarkerConsumesNoLineNumber(): void
    {
        $this->assertSame(
            [[null, null], [1, null], [null, null], [null, 1]],
            $this->numbers(['@@ -1,1 +1,1 @@', '-a', '\\ No newline at end of file', '+b']),
        );
    }

    public function testRowsBeforeTheFirstHunkHeaderAreUnnumbered(): void
    {
        $this->assertSame(
            [[null, null], [null, null], [null, null], [1, 1]],
            $this->numbers(['--- a/x', '+++ b/x', '@@ -1,1 +1,1 @@', ' ok']),
        );
    }

    public function testAnOversizedHunkHeaderDeclinesToNumber(): void
    {
        // (int) would clamp to PHP_INT_MAX, the first ++ would promote to
        // float, and downstream formatting would die. Declining beats printing
        // a clamped — i.e. wrong — line number.
        $numbers = $this->numbers([
            '@@ -99999999999999999999,3 +99999999999999999999,3 @@',
            ' ctx',
            '-old',
        ]);

        $this->assertSame([[null, null], [null, null], [null, null]], $numbers);
    }

    public function testAnOversizedHunkHeaderStopsThePreviousHunksNumbering(): void
    {
        // The header is still RECOGNISED even when unnumberable, so a
        // previous hunk's counters cannot bleed through the rows below it.
        $numbers = $this->numbers([
            '@@ -1,2 +1,2 @@',
            ' first',
            '@@ -99999999999999999999,3 +99999999999999999999,3 @@',
            ' second',
            ' third',
        ]);

        $this->assertSame([1, 1], $numbers[1]);
        $this->assertSame([null, null], $numbers[2]);
        $this->assertSame([null, null], $numbers[3], 'row 3 must not keep counting');
        $this->assertSame([null, null], $numbers[4], 'row 4 must not keep counting');
    }

    public function testTheLargestNumberableHunkStartIsStillNumbered(): void
    {
        $numbers = $this->numbers(['@@ -999999999,1 +999999999,1 @@', ' ctx']);

        $this->assertSame([999999999, 999999999], $numbers[1]);
        $this->assertSame(9, UnifiedScan::MAX_LINE_NUMBER_DIGITS);
    }

    public function testDeletedCommentRowsAreNotMistakenForFileHeaders(): void
    {
        // `--` is the line-comment token in SQL, Lua, Haskell and Ada, so
        // deleting `-- users table` emits `--- users table` — byte-identical
        // to a header. Inside a hunk it is content.
        $numbers = $this->numbers([
            '@@ -10,4 +10,4 @@',
            ' CREATE TABLE users (',
            '--- users table, legacy',
            ' id INT',
            ' );',
        ]);

        $this->assertSame([10, 10], $numbers[1]);
        $this->assertSame([11, null], $numbers[2], 'a removal, not a header');
        $this->assertSame([12, 11], $numbers[3], 'the rest of the hunk keeps its numbers');
        $this->assertSame([13, 12], $numbers[4]);
    }

    public function testAddedRowsStartingWithPlusPlusAreNotMistakenForFileHeaders(): void
    {
        $numbers = $this->numbers([
            '@@ -10,2 +10,3 @@',
            ' for (;;) {',
            '+++ i is the idiom',
            ' }',
        ]);

        $this->assertSame([null, 11], $numbers[2], 'an addition, not a header');
        $this->assertSame([11, 12], $numbers[3]);
    }

    public function testEmptyRowReadsAsStrippedContextAndAdvancesBothCounters(): void
    {
        $this->assertSame(
            [[null, null], [1, 1], [2, 2]],
            $this->numbers(['@@ -1,2 +1,2 @@', ' one', '']),
        );
    }

    public function testFileHeadersReportsTheHeaderVerdictPerRow(): void
    {
        $rows = ['--- a/x', '+++ b/x', '@@ -1,1 +1,1 @@', ' ok'];

        $this->assertSame([true, true, false, false], UnifiedScan::fileHeaders($rows));
    }

    public function testStringInputScansRowForRowAtTheWriterParsesIt(): void
    {
        $patch = Diff::compute("a\n", "b\n")->unified('f.txt');

        $this->assertSame(
            [[null, null], [null, null], [null, null], [1, null], [null, 1]],
            $this->numbers($patch),
            'the trailing newline must not add a phantom row',
        );
    }

    public function testEmptyStringProducesNoRows(): void
    {
        $this->assertSame([], UnifiedScan::lines(''));
    }

    public function testScannedLineExposesItsThreeFields(): void
    {
        $line = new ScannedLine(3, null, false);

        $this->assertSame(3, $line->old);
        $this->assertNull($line->new);
        $this->assertFalse($line->fileHeader);
    }
}
