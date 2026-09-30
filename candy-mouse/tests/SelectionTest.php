<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Selection;
use SugarCraft\Mouse\SelectionRange;

/**
 * The press → drag → release gesture machine: anchoring, region clamping,
 * shift-extend, clear semantics and the drag/click distinction.
 *
 * Pins ported from sugar-crush's `tests/Tui/TextSelectionTest.php`, adapted
 * to the upstream API: 1-based cells (so the region
 * [rowFrom, rowTo, colFrom, colTo] = [2, 5, 4, 13] where downstream read
 * [1, 4, 3, 12]) and a state machine instead of the per-event value object.
 */
final class SelectionTest extends TestCase
{
    /** The 10-column text of a frame with a 3-cell gutter each side, 1-based. */
    private function region(): Selection
    {
        return Selection::new(2, 5, 4, 13);
    }

    public function testAPressOutsideTheRegionAnchorsNothing(): void
    {
        $sel = $this->region();

        self::assertFalse($sel->begin(3, 3), 'left gutter');
        self::assertNull($sel->range());
        self::assertFalse($sel->begin(3, 14), 'right gutter');
        self::assertFalse($sel->begin(1, 5), 'above');
        self::assertFalse($sel->begin(6, 5), 'below');
        self::assertNull($sel->range());
        self::assertFalse(Selection::new(3, 2, 4, 13)->begin(2, 4), 'an empty region');
    }

    public function testAPressInsideAnchorsAnUndraggedSelection(): void
    {
        $sel = $this->region();

        self::assertTrue($sel->begin(3, 6));

        $range = $sel->range();
        self::assertInstanceOf(SelectionRange::class, $range);
        self::assertSame([3, 6, 3, 6], [$range->startRow, $range->startCol, $range->endRow, $range->endCol]);
        self::assertTrue($sel->active());
        self::assertFalse($sel->dragging());
    }

    public function testTheHeadIsClampedIntoTheRegion(): void
    {
        $sel = $this->region();
        $sel->begin(3, 6);

        $right = $sel->range();
        self::assertNotNull($right);

        $sel->dragTo(4, 41);
        [$row, $col] = self::end($sel);
        self::assertSame([4, 13], [$row, $col], 'past the right edge pins to the last column');

        $sel->dragTo(-2, 9);
        [$row, $col] = self::start($sel);
        self::assertSame([2, 4], [$row, $col], 'above the region pins to its first cell');

        $sel->dragTo(31, 5);
        [$row, $col] = self::end($sel);
        self::assertSame([5, 13], [$row, $col], 'below the region pins to its last cell');
    }

    public function testSpansRunInReadingOrderWhicheverWayTheDragWent(): void
    {
        $forward = $this->region();
        $forward->begin(2, 7);
        $forward->dragTo(4, 5);

        $backward = $this->region();
        $backward->begin(4, 5);
        $backward->dragTo(2, 7);

        $fwd = $forward->range();
        self::assertNotNull($fwd);
        self::assertEquals($fwd, $backward->range(), 'the two drag directions cover exactly the same cells');

        self::assertNull($fwd->spanOnRow(1));
        self::assertSame([7, 13], $fwd->spanOnRow(2), 'first row: anchor to the right edge');
        self::assertSame([4, 13], $fwd->spanOnRow(3), 'middle row: whole text column');
        self::assertSame([4, 5], $fwd->spanOnRow(4), 'last row: left edge to the head, inclusive');
        self::assertNull($fwd->spanOnRow(5));

        $sameRow = $this->region();
        $sameRow->begin(3, 10);
        $sameRow->dragTo(3, 6);
        self::assertSame([6, 10], $sameRow->range()?->spanOnRow(3), 'a leftward drag on one row still spans left to right');
    }

    public function testShiftExtendKeepsTheAnchorWhileAPlainPressReplacesIt(): void
    {
        $sel = $this->region();
        $sel->begin(2, 7);
        $sel->dragTo(3, 9);

        self::assertTrue($sel->begin(4, 4, shiftExtend: true));
        $extended = $sel->range();
        self::assertNotNull($extended);
        self::assertSame([2, 7], [$extended->startRow, $extended->startCol], 'the anchor survived the shift-press');
        self::assertSame([4, 4], [$extended->endRow, $extended->endCol], 'the head moved to it');

        self::assertTrue($sel->begin(4, 4));
        $replaced = $sel->range();
        self::assertNotNull($replaced);
        self::assertSame([4, 4, 4, 4], [$replaced->startRow, $replaced->startCol, $replaced->endRow, $replaced->endCol], 'a plain press starts fresh');
    }

    public function testAShiftPressOnChromeDeclinesAndAPlainPressCollapses(): void
    {
        $sel = $this->region();
        $sel->begin(2, 7);

        self::assertFalse($sel->begin(1, 7, shiftExtend: true), 'shift-press above the region');
        $kept = $sel->range();
        self::assertNotNull($kept);
        self::assertSame([2, 7, 2, 7], [$kept->startRow, $kept->startCol, $kept->endRow, $kept->endCol]);

        self::assertFalse($sel->begin(1, 7));
        self::assertNull($sel->range(), 'the plain chrome press dropped the selection, as downstream collapses it');
    }

    public function testAShiftDragWithNothingToExtendIsDeclined(): void
    {
        $sel = $this->region();

        self::assertFalse($sel->dragTo(3, 6, shiftExtend: true));
        self::assertFalse($sel->active());
        self::assertNull($sel->range());

        self::assertTrue($sel->dragTo(3, 6), 'a plain dragTo without a press starts at the pointer');
        $range = $sel->range();
        self::assertNotNull($range);
        self::assertSame([3, 6, 3, 6], [$range->startRow, $range->startCol, $range->endRow, $range->endCol]);
    }

    public function testDraggingIsGeometricNotLatched(): void
    {
        $sel = $this->region();
        $sel->begin(3, 6);

        self::assertFalse($sel->dragging());
        $sel->dragTo(3, 9);
        self::assertTrue($sel->dragging());
        $sel->dragTo(3, 6);
        self::assertFalse($sel->dragging(), 'a there-and-back sweep releases as a click and copies nothing');
    }

    public function testClearDropsTheGestureAndShiftBeginStartsFresh(): void
    {
        $sel = $this->region();
        $sel->begin(2, 7);

        $sel->clear();
        self::assertNull($sel->range());
        self::assertFalse($sel->active());

        self::assertTrue($sel->begin(3, 8, shiftExtend: true), 'extend-from-nothing anchors here');
        $range = $sel->range();
        self::assertNotNull($range);
        self::assertSame([3, 8, 3, 8], [$range->startRow, $range->startCol, $range->endRow, $range->endCol]);
    }

    public function testADragOnAnEmptyRegionSelectsNothing(): void
    {
        self::assertFalse(Selection::new(3, 2, 4, 13)->dragTo(2, 4));
    }

    public function testTheRegionIsFlooredIntoTerminalSpace(): void
    {
        $sel = Selection::new(0, 5, 0, 13);

        self::assertTrue($sel->begin(2, 1), 'colFrom was floored from 0 to 1');
        $range = $sel->range();
        self::assertNotNull($range);
        self::assertSame(1, $range->startCol);
    }

    /**
     * @return array{0:int,1:int} the range's start [row, col]
     */
    private static function start(Selection $sel): array
    {
        $range = $sel->range();
        self::assertNotNull($range);

        return [$range->startRow, $range->startCol];
    }

    /**
     * @return array{0:int,1:int} the range's end [row, col]
     */
    private static function end(Selection $sel): array
    {
        $range = $sel->range();
        self::assertNotNull($range);

        return [$range->endRow, $range->endCol];
    }
}
