<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests\Writer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;
use SugarCraft\Diff\LineKind;
use SugarCraft\Diff\Writer\SideBySideFormat;
use SugarCraft\Diff\Writer\SideBySideRow;

/**
 * Pins for the side-by-side row pairing: removal/added runs pair line by
 * line, leftovers stand alone, context passes through paired with itself,
 * nothing pairs across a hunk boundary, and the reader path
 * ({@see SideBySideFormat::fromUnified()}) yields exactly the rows the
 * writer path yields for the same change — including when the writer
 * annotates an unterminated end-of-file row.
 */
final class SideBySideFormatTest extends TestCase
{
    /** @return list<array{0:?string,1:?string}> left/right texts per row */
    private static function shape(array $rows): array
    {
        return array_map(
            static fn (SideBySideRow $r): array => [
                $r->left?->text,
                $r->right?->text,
            ],
            $rows,
        );
    }

    public function testReplaceRunPairsLineByLine(): void
    {
        $rows = SideBySideFormat::rows(Diff::compute("a\nb\n", "x\ny\n"));

        $this->assertSame([['a', 'x'], ['b', 'y']], self::shape($rows));
    }

    public function testEachSideCarriesItsOwnKindAndLineNumbers(): void
    {
        $rows = SideBySideFormat::rows(Diff::compute("a\n", "x\n"));
        $row = $rows[0];

        $this->assertTrue($row->isPaired());
        $this->assertSame(LineKind::Delete, $row->left?->kind);
        $this->assertSame(1, $row->left?->oldNumber);
        $this->assertNull($row->left?->newNumber);
        $this->assertSame(LineKind::Insert, $row->right?->kind);
        $this->assertSame(1, $row->right?->newNumber);
        $this->assertNull($row->right?->oldNumber);
    }

    public function testMoreRemovalsThanAdditionsLeaveTheExtraLinesOnTheLeft(): void
    {
        // 'b' and 'c' go, only 'x' arrives: c pairs with nothing and stands
        // alone below the paired b/x row. The leading context row passes
        // through paired (default 3-line context covers this whole file).
        $rows = SideBySideFormat::rows(Diff::compute("a\nb\nc\n", "a\nx\n"));

        $this->assertSame([['a', 'a'], ['b', 'x'], ['c', null]], self::shape($rows));
    }

    public function testMoreAdditionsThanRemovalsLeaveTheExtraLinesOnTheRight(): void
    {
        $rows = SideBySideFormat::rows(Diff::compute("a\nb\n", "a\nx\ny\nz\n"));

        $this->assertSame([['a', 'a'], ['b', 'x'], [null, 'y'], [null, 'z']], self::shape($rows));
    }

    public function testRunsSeparatedByContextDoNotPairAcrossIt(): void
    {
        // 'a' goes, 'b' stays, 'X' arrives. The context row splits the two
        // changed runs: pairing 'a' with 'X' would show the addition as
        // replacing a line above the common row it actually sits under.
        $diff = Diff::compute("a\nb\n", "b\nX\n");

        $this->assertSame([['a', null], ['b', 'b'], [null, 'X']], self::shape(SideBySideFormat::rows($diff)));
        $this->assertSame(
            [['a', null], ['b', 'b'], [null, 'X']],
            self::shape(SideBySideFormat::fromUnified($diff->unified('f.txt'))),
        );
    }

    public function testContextPassesThroughPairedWithItself(): void
    {
        $rows = SideBySideFormat::rows(Diff::compute("keep\ngone\n", "keep\nadded\n"));
        $context = $rows[0];

        $this->assertSame('keep', $context->left?->text);
        $this->assertSame($context->left, $context->right, 'one immutable row fills both halves');
        $this->assertSame(LineKind::Equal, $context->left?->kind);
        $this->assertSame(1, $context->left?->oldNumber);
        $this->assertSame(1, $context->left?->newNumber);
        $this->assertSame([['keep', 'keep'], ['gone', 'added']], self::shape($rows));
    }

    public function testPairingNeverCrossesAHunkBoundary(): void
    {
        // -U0 shape: hunk 1 removes 'a', hunk 2 replaces 'y' with 'z'. If the
        // two regions were walked as one run, 'a' would pair with 'z' — a
        // removal in one hunk shown as replaced by an addition in another.
        $options = DiffOptions::new()->withContextLines(0);
        $diff = Diff::compute("a\nx\nx\ny\n", "x\nx\nz\n", $options);

        $this->assertCount(2, $diff->hunks, 'the edits must be far enough apart to stay two hunks');
        $this->assertSame([['a', null], ['y', 'z']], self::shape(SideBySideFormat::rows($diff)));
        $this->assertSame(
            self::shape(SideBySideFormat::rows($diff)),
            self::shape(SideBySideFormat::fromUnified($diff->unified('f.txt'))),
            'the reader path must not pair across the @@ boundary either',
        );
    }

    public function testEmptyDiffProducesNoRows(): void
    {
        $this->assertSame([], SideBySideFormat::rows(Diff::compute('same', 'same')));
        $this->assertSame([], SideBySideFormat::fromUnified(''));
    }

    public function testFileHeadersAreSkippedRatherThanCountedAsEdits(): void
    {
        $patch = "diff --git a/f.txt b/f.txt\n"
            . "index 1111111..2222222 100644\n"
            . "--- a/f.txt\n"
            . "+++ b/f.txt\n"
            . "@@ -1 +1 @@\n"
            . "-old\n"
            . "+new\n";

        $this->assertSame([['old', 'new']], self::shape(SideBySideFormat::fromUnified($patch)));
    }

    public function testNoNewlineAnnotationDoesNotSplitAPairingRun(): void
    {
        // GNU writes the annotation between the removal and the addition here
        // (the old side ends unterminated). It occupies no line of either
        // file, so it must not stand alone on the left and leave 'add' alone
        // on the right — both paths must still show one paired row.
        $options = DiffOptions::new()->withNoNewlineMarker();
        $diff = Diff::compute('del', 'add', $options);

        $this->assertStringContainsString("\\ No newline at end of file\n", $diff->unified('f'));
        $this->assertSame([['del', 'add']], self::shape(SideBySideFormat::rows($diff)));
        $this->assertSame(
            self::shape(SideBySideFormat::rows($diff)),
            self::shape(SideBySideFormat::fromUnified($diff->unified('f'))),
        );
    }

    /**
     * Parity battery: for every change, the hunk-model path and the patch-
     * text path must return equal rows — same pairing, same kinds, same
     * side-specific line numbers, verbatim texts.
     *
     * @return array<string, array{0:string, 1:string, 2:?DiffOptions}>
     */
    public static function parityFixtures(): array
    {
        $wide = DiffOptions::new()->withContextLines(5);
        $tight = DiffOptions::new()->withContextLines(0);
        $markers = DiffOptions::new()->withNoNewlineMarker();
        $devNull = DiffOptions::new()->withDevNullOnEmptySide();

        return [
            'simple replace' => ["one\ntwo\nthree\n", "one\nTWO\nthree\n", null],
            'uneven left' => ["a\nb\nc\nd\n", "a\nx\nd\n", $wide],
            'uneven right' => ["a\nb\n", "a\nx\ny\nz\n", $wide],
            'insert only' => ["a\nb\n", "a\nnew\nb\n", $wide],
            'delete only' => ["a\nb\nc\n", "a\nc\n", $wide],
            'file added' => ['', "brand new\n", $devNull],
            'file removed' => ["brand new\n", '', $devNull],
            'two hunks with context' => [
                "1\n2\n3\n4\n5\n6\n7\n8\n9\n10\n11\n12\n",
                "X\n2\n3\n4\n5\n6\n7\n8\n9\n10\n11\nY\n",
                null,
            ],
            'tight hunks do not merge' => ["a\nk1\nk2\nk3\nk4\nb\n", "p\nk1\nk2\nk3\nk4\nq\n", $tight],
            'blank content lines' => ["a\n\nb\n", "a\n\n\nb\n", $wide],
            'empty context rows' => ["keep\n\ngone\n", "keep\n\nadded\n", $wide],
            'sql comment shaped removals' => ["-- drop table\nuseful\n++ counter\n", "-- alter table\nuseful\n++ count\n", $wide],
            'hunk-header shaped content' => ["@@ -1 +1 @@\nreal\n", "@@ -2 +2 @@\nreal\n", $wide],
            'unterminated both ends' => ["same\nold-tail", "same\nnew-tail", $markers],
            'unterminated old side only' => ["head\ntail-no-eol", "head\ntail-no-eol\nadded\n", $markers],
            'unterminated new side only' => ["head\ntail\n", "head\n", $markers],
            'no context replace at eof' => ["a\nlast-old", "a\nlast-new", $tight],
            'crlf content' => ["a\r\nb\r\n", "a\r\nc\r\n", $wide],
        ];
    }

    /**
     * @param string     $before
     * @param string     $after
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('parityFixtures')]
    public function testFromUnifiedReturnsTheSameRowsAsFromADiff(string $before, string $after, ?DiffOptions $options): void
    {
        $diff = Diff::compute($before, $after, $options);

        $this->assertEquals(
            SideBySideFormat::rows($diff),
            SideBySideFormat::fromUnified($diff->unified('f.txt', 'g.txt')),
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('parityFixtures')]
    public function testEveryParityFixtureActuallyPairsSomething(string $before, string $after, ?DiffOptions $options): void
    {
        $rows = SideBySideFormat::rows(Diff::compute($before, $after, $options));

        // Vacuity arm: a fixture that produced zero rows would let the parity
        // test above pass against a dead reader path.
        $this->assertNotSame([], $rows, 'fixture carries a change the walk must show');
        foreach ($rows as $row) {
            $this->assertTrue(
                $row->left !== null || $row->right !== null,
                'a produced row always carries at least one side',
            );
        }
    }
}
