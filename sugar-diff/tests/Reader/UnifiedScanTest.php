<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests\Reader;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\Reader\ScanRowKind;
use SugarCraft\Diff\Reader\ScannedLine;
use SugarCraft\Diff\Reader\UnifiedScan;

/**
 * Region-model pins ported from candy-code's tests/Tui/DiffGutterTest.php —
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

    public function testNoNewlineAnnotationConsumesNoLineNumber(): void
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

    public function testGitExtendedHeaderLinesAreNotFileHeaders(): void
    {
        // The M1 behaviour half: rename/mode/CIM extended rows carry none of
        // the recognised header prefixes, so they read as unnumbered
        // out-of-hunk prose — only git's `index <sha>..` line starts with a
        // recognised prefix and counts.
        $rows = [
            'diff --git a/legacy.txt b/newname.txt',
            'similarity index 90%',
            'rename from legacy.txt',
            'rename to newname.txt',
            'old mode 100644',
            'new mode 100755',
            'index 7f8f9a0..1234567 100644',
        ];

        $this->assertSame([true, false, false, false, false, false, true], UnifiedScan::fileHeaders($rows));

        $this->assertSame(
            [
                ScanRowKind::FileHeader,
                ScanRowKind::OutsideHunk,
                ScanRowKind::OutsideHunk,
                ScanRowKind::OutsideHunk,
                ScanRowKind::OutsideHunk,
                ScanRowKind::OutsideHunk,
                ScanRowKind::FileHeader,
            ],
            array_map(static fn (ScannedLine $l): ScanRowKind => $l->kind, UnifiedScan::lines($rows)),
        );

        $this->assertSame(
            array_fill(0, 7, [null, null]),
            $this->numbers($rows),
            'none of these rows carries a line number',
        );
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

    /**
     * sugar-diff-018: the documented fourth-field default (ScannedLine.php:33)
     * — a 2-arg-era construction is an unattributed row, not a context row.
     */
    public function testScannedLineDefaultsToOutsideHunkWithoutTheKindArgument(): void
    {
        $line = new ScannedLine(3, null, false);

        $this->assertSame(ScanRowKind::OutsideHunk, $line->kind);
    }

    /**
     * sugar-diff-019: rowsOf()'s terminator rule — a trailing newline ends
     * the last row, it does not add an empty one. These are the shapes the
     * writer pairs against; each was fuzz-true, none was pinned.
     */
    public function testRowsOfFollowsTheTerminatorNotAnExtraEmptyRow(): void
    {
        $this->assertSame([], UnifiedScan::rowsOf(''));
        $this->assertSame([''], UnifiedScan::rowsOf("\n"));
        $this->assertSame(['a', ''], UnifiedScan::rowsOf("a\n\n"));
        $this->assertSame(['a', 'b'], UnifiedScan::rowsOf("a\nb\n"));
        $this->assertSame(['a', 'b'], UnifiedScan::rowsOf("a\nb"));
    }

    /**
     * sugar-diff-019: the renderer contract — every row rowsOf() yields is
     * numbered by lines() count-for-count (the writer's index alignment with
     * the split patch text depends on it, and nothing pinned it).
     */
    public function testLinesNumberRowsOfOutputCountForCount(): void
    {
        $patch = "@@ -1,3 +1,4 @@\n a\n-b\n+B\n c\n+D\n"
            . "@@ -10 +11 @@\n-x\n+y\n";
        $rows = UnifiedScan::rowsOf($patch);
        $scanned = UnifiedScan::lines($patch);

        $this->assertCount(count($rows), $scanned, 'one scanned row per rowsOf() row');
        $this->assertSame(['@@ -10 +11 @@'], array_slice($rows, 6, 1), 'the second hunk header lands where the walk saw it');
        // kinds are the observable alignment between the two splits.
        $kinds = array_map(static fn (ScannedLine $l): string => $l->kind->name, $scanned);
        $this->assertSame(['HunkHeader', 'Context', 'Delete', 'Insert', 'Context', 'Insert', 'HunkHeader', 'Delete', 'Insert'], $kinds);
    }

    public function testThePublishedHunkHeaderPredicateRecognisesEveryShapeTheWalkRecognises(): void
    {
        // The unbounded \d+ is deliberate (see MAX_LINE_NUMBER_DIGITS):
        // recognition is total, only the numbering opts out — a refused
        // number must still close the hunk above it.
        foreach ([
            '@@ -1 +1 @@',
            '@@ -1,3 +1,2 @@ ctx',
            '@@ -0,0 +1 @@',
            '@@ -99999999999 +1 @@',
            '@@ -1 +1 @@ trailing annotation text',
        ] as $header) {
            $this->assertTrue(UnifiedScan::isHunkHeader($header), $header);
        }

        foreach ([
            '',
            '@ -1 +1 @@',
            '@@ -1 +1 @',
            '@@ - 1 +1 @@',
            '@@--1+1@@',
            'x @@ -1 +1 @@',
            '--- a/f',
            '+++ b/f',
            '\\ No newline at end of file',
        ] as $row) {
            $this->assertFalse(UnifiedScan::isHunkHeader($row), $row);
        }
    }

    public function testThePredicateAndTheWalkShareOneRecognition(): void
    {
        // A consumer gating rows on isHunkHeader() must see exactly the
        // rows the walk classifies as HunkHeader — the pattern is shared
        // with the walk on purpose, so a re-inlined copy could drift.
        $rows = [
            'diff --git a/f b/f',
            'index 7f8f9a0..1234567 100644',
            '--- a/f',
            '+++ b/f',
            '@@ -1,2 +1,2 @@',
            ' ctx',
            '-old',
            '+new',
            '@@ -99999999999 +1 @@',
            ' after an oversized boundary',
        ];

        $walked = array_map(
            static fn (ScannedLine $line): bool => $line->kind === ScanRowKind::HunkHeader,
            UnifiedScan::lines($rows),
        );
        $published = array_map(
            static fn (string $row): bool => UnifiedScan::isHunkHeader($row),
            $rows,
        );

        $this->assertSame($walked, $published);
    }

    public function testFileHeadersIsTheSameWalkProjectedOntoHeaderBits(): void
    {
        // Drift catcher (sugar-diff-025 leg B): the header-only path and the
        // full row walk are one state machine — the bool list must equal the
        // lines() projection on every shape, hostile ones included.
        $rows = [
            'diff --git a/x b/x',
            'index 123..456 100644',
            '--- a/x',
            '+++ b/x',
            '@@ -1,2 +1,2 @@',
            '-a',
            '+b',
            ' ctx',
            '\\ No newline at end of file',
            'junk after spent budget',
            '@@ -999999999999999999 +1 @@',
            '-x',
        ];

        $projected = array_map(
            static fn (ScannedLine $row): bool => $row->fileHeader,
            UnifiedScan::lines($rows),
        );
        $this->assertSame($projected, UnifiedScan::fileHeaders($rows));
        $this->assertSame([true, true, true, true], array_slice($projected, 0, 4));
    }

    public function testCombinedDiffRowsAreUnnumberedByDesign(): void
    {
        // sugar-diff-031: git's combined (--cc) grammar is out of scope — the
        // @@@ header must NOT be mistaken for a hunk start, or a later
        // marker-column "improvement" would silently start counting ' -x'
        // rows as numbered deletes.
        $combined = '@@@ -1,2 -1,4 +1,3 @@@';
        $this->assertFalse(UnifiedScan::isHunkHeader($combined));

        $rows = array_map(
            static fn (ScannedLine $row): string => $row->kind->name,
            UnifiedScan::lines([$combined, ' -x', '+-y', '++z']),
        );
        $this->assertSame(
            ['OutsideHunk', 'OutsideHunk', 'OutsideHunk', 'OutsideHunk'],
            $rows,
        );
        $this->assertSame([false, false, false, false], UnifiedScan::fileHeaders([$combined, ' -x', '+-y', '++z']));
    }
}
