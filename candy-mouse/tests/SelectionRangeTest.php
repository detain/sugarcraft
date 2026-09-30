<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\SelectionRange;

/**
 * The frozen geometry of a text selection: reading-order normalisation,
 * stream-not-block spans, exact cell coverage, the cell walk and the copy
 * half. Pins adapted from sugar-crush's `tests/Tui/TextSelectionTest.php` to
 * 1-based cells (see {@see SelectionTest} for the header note).
 */
final class SelectionRangeTest extends TestCase
{
    public function testNewNormalisesBothDragDirections(): void
    {
        $forward = SelectionRange::new(2, 7, 4, 5, 4, 13);
        $backward = SelectionRange::new(4, 5, 2, 7, 4, 13);

        self::assertEquals($forward, $backward, 'swapping the endpoints describes the same cells');
        self::assertSame([2, 7], [$forward->startRow, $forward->startCol]);
        self::assertSame([4, 5], [$forward->endRow, $forward->endCol]);
        self::assertSame([4, 13], [$forward->edgeFrom, $forward->edgeTo]);
    }

    public function testSpanOnRowIsAStreamNotABlock(): void
    {
        $range = SelectionRange::new(2, 7, 4, 5, 4, 13);

        self::assertNull($range->spanOnRow(1), 'above the selection');
        self::assertSame([7, 13], $range->spanOnRow(2), 'first row runs from the start to the right edge');
        self::assertSame([4, 13], $range->spanOnRow(3), 'middle rows are whole');
        self::assertSame([4, 5], $range->spanOnRow(4), 'last row stops at the end');
        self::assertNull($range->spanOnRow(5), 'below the selection');

        $oneRow = SelectionRange::new(3, 10, 3, 6, 4, 13);
        self::assertSame([6, 10], $oneRow->spanOnRow(3), 'both endpoints on one row bound it, the edges do not widen it');
        self::assertNull($oneRow->spanOnRow(2));
        self::assertNull($oneRow->spanOnRow(4));
    }

    public function testCellContainsFollowsTheStreamNotTheBoundingBox(): void
    {
        $range = SelectionRange::new(2, 7, 4, 5, 4, 13);

        self::assertFalse($range->cellContains(2, 5), 'inside the bbox rectangle, but left of where the drag started');
        self::assertTrue($range->cellContains(2, 7), 'the start cell is inclusive');
        self::assertTrue($range->cellContains(2, 13));
        self::assertTrue($range->cellContains(3, 4), 'middle rows sweep the whole text column');
        self::assertTrue($range->cellContains(3, 13));
        self::assertFalse($range->cellContains(3, 14), 'past the region edge is never covered');
        self::assertTrue($range->cellContains(4, 5), 'the end cell is inclusive');
        self::assertFalse($range->cellContains(4, 6), 'right of where the drag ended');
        self::assertFalse($range->cellContains(1, 7), 'outside the row span');
    }

    public function testCellsWalksCoveredCellsInReadingOrder(): void
    {
        $range = SelectionRange::new(2, 7, 4, 5, 4, 13);

        $cells = iterator_to_array($range->cells(), preserve_keys: false);

        self::assertCount(19, $cells, '7 on the first row, 10 whole, 2 on the last');
        self::assertSame([2, 7], $cells[0]);
        self::assertSame([2, 13], $cells[6]);
        self::assertSame([3, 4], $cells[7], 'the walk drops to the region edge for the middle row');
        self::assertSame([4, 5], $cells[18]);
    }

    public function testExtractCopiesTextNotChromeAndKeepsIndentation(): void
    {
        $lines = [
            '╭──────────────╮',
            '│  ' . "\e[1malpha beta\e[0m" . '  │',
            '│      f();    │',
            '│  omega       │',
            '│              │',
            '╰──────────────╯',
        ];
        $range = SelectionRange::new(2, 4, 5, 13, 4, 13);

        self::assertSame(
            "alpha beta\n    f();\nomega",
            $range->extract($lines),
            'no border glyphs, no SGR, trailing padding and the trailing blank row trimmed, leading indent kept',
        );
    }

    public function testExtractStartsMidRowAtTheAnchor(): void
    {
        $lines = ['', '│  alpha beta  │'];
        $range = SelectionRange::new(2, 10, 2, 13, 4, 13);

        self::assertSame('beta', $range->extract($lines));
    }

    public function testExtractTrimsBlankRowsAtEitherEnd(): void
    {
        $lines = ['', '│              │', '│  hi          │', '│              │', ''];
        $range = SelectionRange::new(2, 4, 4, 13, 4, 13);

        self::assertSame('hi', $range->extract($lines), 'a drag through padding rows copies no stray newlines');
    }

    public function testEdgesDefaultToTheEndpointsOwnExtent(): void
    {
        $range = SelectionRange::new(2, 8, 4, 5);

        self::assertSame([5, 8], [$range->edgeFrom, $range->edgeTo]);
        self::assertSame([8, 8], $range->spanOnRow(2));
        self::assertSame([5, 8], $range->spanOnRow(3), 'a bare range fills its bounding box');
        self::assertSame([5, 5], $range->spanOnRow(4));
    }

    /**
     * @return array<string, array{0:int,1:int,2:int,3:int,4:int|null,5:int|null}>
     */
    public static function offTerminalCellPairs(): array
    {
        return [
            'start row 0'        => [0, 4, 2, 5, null, null],
            'start col 0'        => [2, 0, 4, 5, null, null],
            'end row negative'   => [2, 4, -1, 5, null, null],
            'end col 0'          => [2, 4, 5, 0, null, null],
            'edge from 0'        => [2, 4, 5, 6, 0, 9],
            'edge to negative'   => [2, 4, 5, 6, 1, -2],
        ];
    }

    #[DataProvider('offTerminalCellPairs')]
    public function testNewRefusesOffTerminalCells(int $rowA, int $colA, int $rowB, int $colB, ?int $edgeFrom, ?int $edgeTo): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('1-based terminal cells');

        SelectionRange::new($rowA, $colA, $rowB, $colB, $edgeFrom, $edgeTo);
    }

    public function testInvertedEdgesAreNormalisedNotObeyed(): void
    {
        $range = SelectionRange::new(2, 4, 4, 9, 13, 4);

        self::assertSame([4, 13], [$range->edgeFrom, $range->edgeTo], 'a swapped edge pair describes the same column');
        self::assertSame([4, 13], $range->spanOnRow(3));
    }
}
