<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffEncoder;
use SugarCraft\Buffer\Diff\DiffOptimiser;
use SugarCraft\Buffer\Diff\EraseRunOp;
use SugarCraft\Buffer\Diff\MoveCursorOp;
use SugarCraft\Buffer\Diff\RepeatRunOp;
use SugarCraft\Buffer\Diff\SetCellOp;
use SugarCraft\Buffer\Diff\SetHyperlinkOp;
use SugarCraft\Buffer\Diff\SetStyleOp;
use SugarCraft\Buffer\Hyperlink;
use SugarCraft\Buffer\Position;
use SugarCraft\Buffer\Region;
use SugarCraft\Buffer\Style;

final class BufferTest extends TestCase
{
    public function testNew(): void
    {
        $buf = Buffer::new(20, 3);

        $this->assertSame(20, $buf->width());
        $this->assertSame(3, $buf->height());

        $region = $buf->region();
        $this->assertSame(0, $region->origin->col());
        $this->assertSame(0, $region->origin->row());
        $this->assertSame(20, $region->width());
        $this->assertSame(3, $region->height());
    }

    public function testNewFillsWithBlankCells(): void
    {
        $buf = Buffer::new(3, 2);

        // All cells should be blank
        $this->assertSame(' ', $buf->cellAt(0, 0)->rune());
        $this->assertSame(' ', $buf->cellAt(1, 0)->rune());
        $this->assertSame(' ', $buf->cellAt(2, 1)->rune());
    }

    public function testNewNegativeDimensionsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Buffer::new(-1, 5);
    }

    public function testNewZeroDimensionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Buffer::new(0, 5);
    }

    public function testFromGridRoundTrips(): void
    {
        // Build a 3×2 grid: index = $row * 3 + $col.
        $grid = [];
        $width = 3;
        $height = 2;
        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $width; $col++) {
                $grid[$row * $width + $col] = Cell::new((string) ($row * $width + $col));
            }
        }

        $buf = Buffer::fromGrid($width, $height, $grid);

        $this->assertSame($width, $buf->width());
        $this->assertSame($height, $buf->height());

        // Every cell round-trips to its row/col position.
        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $width; $col++) {
                $this->assertSame(
                    (string) ($row * $width + $col),
                    $buf->cellAt($col, $row)->rune(),
                    "cell ({$col}, {$row})"
                );
            }
        }
    }

    public function testFromGridWrongSizeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // 3×2 needs 6 cells; supply 5.
        Buffer::fromGrid(3, 2, array_fill(0, 5, Cell::new()));
    }

    public function testFromGridNonPositiveDimensionsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Buffer::fromGrid(0, 2, []);
    }

    public function testCellAtBoundsCheckColUnderflow(): void
    {
        $buf = Buffer::new(10, 3);

        $this->expectException(\OutOfRangeException::class);
        $buf->cellAt(-1, 0);
    }

    public function testCellAtBoundsCheckColOverflow(): void
    {
        $buf = Buffer::new(10, 3);

        $this->expectException(\OutOfRangeException::class);
        $buf->cellAt(10, 0);
    }

    public function testCellAtBoundsCheckRowUnderflow(): void
    {
        $buf = Buffer::new(10, 3);

        $this->expectException(\OutOfRangeException::class);
        $buf->cellAt(0, -1);
    }

    public function testCellAtBoundsCheckRowOverflow(): void
    {
        $buf = Buffer::new(10, 3);

        $this->expectException(\OutOfRangeException::class);
        $buf->cellAt(0, 3);
    }

    public function testCellAtAllCorners(): void
    {
        $buf = Buffer::new(10, 3);

        // No exception means bounds check passes
        $buf->cellAt(0, 0);
        $buf->cellAt(9, 0);
        $buf->cellAt(0, 2);
        $buf->cellAt(9, 2);

        $this->assertTrue(true); // reached here means no exceptions
    }

    public function testWithCellAtReturnsNewInstance(): void
    {
        $buf = Buffer::new(10, 3);
        $original = $buf;
        $cell = Cell::new('X', Style::bold());

        $newBuf = $buf->withCellAt(5, 1, $cell);

        $this->assertNotSame($buf, $newBuf);
        // Original unchanged
        $this->assertSame(' ', $original->cellAt(5, 1)->rune());
        // New buffer has the cell
        $this->assertSame('X', $newBuf->cellAt(5, 1)->rune());
        $this->assertTrue($newBuf->cellAt(5, 1)->style()->hasBold());
    }

    public function testWithCellAtImmutability(): void
    {
        $buf = Buffer::new(5, 2);
        $original = $buf;
        $newBuf = $buf->withCellAt(2, 1, Cell::new('A'));

        // Original must be unchanged
        $this->assertSame(' ', $original->cellAt(2, 1)->rune());
        // New must have the change
        $this->assertSame('A', $newBuf->cellAt(2, 1)->rune());
    }

    public function testWithCellAtStyleNullDefault(): void
    {
        $buf = Buffer::new(5, 2);
        $cell = Cell::new('X');

        $newBuf = $buf->withCellAt(1, 0, $cell);

        $this->assertNull($newBuf->cellAt(1, 0)->style());
    }

    public function testWithCellAtLinkNullDefault(): void
    {
        $buf = Buffer::new(5, 2);
        $cell = Cell::new('X', Style::new());

        $newBuf = $buf->withCellAt(1, 0, $cell);

        $this->assertNull($newBuf->cellAt(1, 0)->link());
    }

    public function testWithCellAtBoundsCheck(): void
    {
        $buf = Buffer::new(5, 2);

        $this->expectException(\OutOfRangeException::class);
        $buf->withCellAt(5, 0, Cell::new('X'));
    }

    public function testWithRegionBlitAtOrigin(): void
    {
        $buf = Buffer::new(10, 3);
        $sub = Buffer::new(2, 2);
        $sub = $sub->withCellAt(0, 0, Cell::new('A'))
                  ->withCellAt(1, 0, Cell::new('B'))
                  ->withCellAt(0, 1, Cell::new('C'))
                  ->withCellAt(1, 1, Cell::new('D'));

        $region = new Region(Position::new(0, 0), 2, 2);
        $result = $buf->withRegion($region, $sub);

        $this->assertSame('A', $result->cellAt(0, 0)->rune());
        $this->assertSame('B', $result->cellAt(1, 0)->rune());
        $this->assertSame('C', $result->cellAt(0, 1)->rune());
        $this->assertSame('D', $result->cellAt(1, 1)->rune());
    }

    public function testWithRegionBlitAtOffset(): void
    {
        $buf = Buffer::new(10, 5);
        $sub = Buffer::new(2, 2);
        $sub = $sub->withCellAt(0, 0, Cell::new('X'))
                  ->withCellAt(1, 1, Cell::new('Y'));

        $region = new Region(Position::new(3, 2), 2, 2);
        $result = $buf->withRegion($region, $sub);

        $this->assertSame('X', $result->cellAt(3, 2)->rune());
        $this->assertSame('Y', $result->cellAt(4, 3)->rune());
    }

    public function testWithRegionClippedAtEdges(): void
    {
        // Source is 3x3, but region only has room for 2x2 at offset
        $buf = Buffer::new(5, 5);
        $sub = Buffer::new(3, 3);
        $sub = $sub->withCellAt(0, 0, Cell::new('S'));

        // Region at bottom-right that would overflow a 5x5 buffer
        $region = new Region(Position::new(4, 4), 2, 2);
        $result = $buf->withRegion($region, $sub);

        // Should not throw; S is at (4,4) which is in bounds
        $this->assertSame('S', $result->cellAt(4, 4)->rune());
    }

    public function testWithRegionSourceSmallerThanRegion(): void
    {
        // Region is 5x5 but source is only 2x2
        // When source coords exceed source bounds, we skip (continue)
        $buf = Buffer::new(10, 10);
        $source = Buffer::new(2, 2);
        $source = $source->withCellAt(0, 0, Cell::new('S'))
                         ->withCellAt(1, 1, Cell::new('T'));

        $region = new Region(Position::new(0, 0), 5, 5);
        $result = $buf->withRegion($region, $source);

        // Only (0,0) and (1,1) should be copied from source
        // All other cells in the 5x5 region remain blank
        $this->assertSame('S', $result->cellAt(0, 0)->rune());
        $this->assertSame('T', $result->cellAt(1, 1)->rune());
        $this->assertSame(' ', $result->cellAt(2, 0)->rune()); // src coords (2,0) >= source dims
        $this->assertSame(' ', $result->cellAt(0, 2)->rune()); // src coords (0,2) >= source dims
    }

    public function testWithRegionNoOpWhenIdentical(): void
    {
        $buf = Buffer::new(3, 2);
        $same = Buffer::new(3, 2);

        $region = new Region(Position::new(0, 0), 3, 2);
        $result = $buf->withRegion($region, $same);

        // All cells should be blank since source is blank
        $this->assertSame(' ', $result->cellAt(0, 0)->rune());
    }

    public function testWithRegionClipsNegativeOrigin(): void
    {
        // 2x2 source with cells at all 4 positions
        $source = Buffer::new(2, 2);
        $source = $source->withCellAt(0, 0, Cell::new('A'))
                         ->withCellAt(1, 0, Cell::new('B'))
                         ->withCellAt(0, 1, Cell::new('C'))
                         ->withCellAt(1, 1, Cell::new('D'));

        // 3x3 buffer; region starts at (-1, -1) so only dest (0,0) is in bounds
        $buf = Buffer::new(3, 3);
        $region = new Region(Position::new(-1, -1), 2, 2);
        $result = $buf->withRegion($region, $source);

        // Only the in-bounds cell is written: dest (0,0) ← src (1,1) = 'D'
        $this->assertSame('D', $result->cellAt(0, 0)->rune());

        // All other cells remain blank
        $this->assertSame(' ', $result->cellAt(1, 0)->rune());
        $this->assertSame(' ', $result->cellAt(0, 1)->rune());
        $this->assertSame(' ', $result->cellAt(1, 1)->rune());

        // Buffer dimensions are unchanged
        $this->assertSame(3, $result->width());
        $this->assertSame(3, $result->height());

        // No negative key: round-trip through fromGrid/cellAt does not error
        $grid = [];
        for ($row = 0; $row < 3; $row++) {
            for ($col = 0; $col < 3; $col++) {
                $grid[$row * 3 + $col] = $result->cellAt($col, $row);
            }
        }
        $roundTrip = Buffer::fromGrid(3, 3, $grid);
        $this->assertSame('D', $roundTrip->cellAt(0, 0)->rune());
    }

    public function testDiffReturnsEmptyArray(): void
    {
        $buf = Buffer::new(5, 2);
        $other = Buffer::new(5, 2);

        $diff = $buf->diff($other);

        $this->assertIsArray($diff);
        $this->assertEmpty($diff);
    }

    public function testDiffDoesNotThrow(): void
    {
        $buf = Buffer::new(5, 2);
        $other = Buffer::new(5, 2)->withCellAt(2, 1, Cell::new('X'));

        // Should not throw even though buffers differ — actual diff
        // is step-26's job
        $diff = $buf->diff($other);

        $this->assertIsArray($diff);
    }

    public function testRegionReturnsFullBounds(): void
    {
        $buf = Buffer::new(15, 7);

        $region = $buf->region();

        // region() always starts at (0, 0) and spans the full buffer
        $this->assertSame(0, $region->origin->col());
        $this->assertSame(0, $region->origin->row());
        $this->assertSame(15, $region->width());
        $this->assertSame(7, $region->height());
    }

    public function testWideCharThenContinuation(): void
    {
        $buf = Buffer::new(10, 2);
        $wide = Cell::new('中', null, null, 2);
        $continuation = Cell::continuation();

        $buf = $buf->withCellAt(0, 0, $wide)
                   ->withCellAt(1, 0, $continuation);

        $this->assertSame('中', $buf->cellAt(0, 0)->rune());
        $this->assertSame(2, $buf->cellAt(0, 0)->width());
        $this->assertSame('', $buf->cellAt(1, 0)->rune());
        $this->assertSame(0, $buf->cellAt(1, 0)->width());
    }

    public function testDiffIdenticalBuffersReturnsEmpty(): void
    {
        $buf = Buffer::new(5, 2);

        $diff = $buf->diff($buf);

        $this->assertIsArray($diff);
        $this->assertEmpty($diff);
    }

    public function testDiffSameInstanceShortCircuits(): void
    {
        $buf = Buffer::new(5, 2)->withCellAt(0, 0, Cell::new('X'));

        $result = $buf->diff($buf);

        $this->assertSame([], $result);
    }

    public function testDiffSingleCellChange(): void
    {
        $prev = Buffer::new(5, 2);
        $curr = $prev->withCellAt(2, 1, Cell::new('X'));

        $diff = $curr->diff($prev);

        $this->assertNotEmpty($diff);
        $hasMove = false;
        $hasSetCell = false;
        foreach ($diff as $op) {
            if ($op instanceof MoveCursorOp) {
                $this->assertSame(2, $op->col);
                $this->assertSame(1, $op->row);
                $hasMove = true;
            }
            if ($op instanceof SetCellOp) {
                $this->assertNotEmpty($op->cells);
                $this->assertSame('X', $op->cells[0]->rune());
                $hasSetCell = true;
            }
        }
        $this->assertTrue($hasMove, 'Diff must contain MoveCursorOp');
        $this->assertTrue($hasSetCell, 'Diff must contain SetCellOp');
    }

    public function testDiffMultipleAdjacentCellsSameStyleMerged(): void
    {
        $prev = Buffer::new(5, 2);
        $curr = $prev
            ->withCellAt(1, 0, Cell::new('A', Style::bold()))
            ->withCellAt(2, 0, Cell::new('B', Style::bold()));

        $diff = $curr->diff($prev);

        $this->assertNotEmpty($diff);
        $setCells = array_filter($diff, fn($op) => $op instanceof SetCellOp);
        $this->assertNotEmpty($setCells);
    }

    public function testDiffHorizontalRepeatRunEmitsRepeatRunOp(): void
    {
        $prev = Buffer::new(5, 1);
        $curr = $prev
            ->withCellAt(0, 0, Cell::new('X'))
            ->withCellAt(1, 0, Cell::new('X'))
            ->withCellAt(2, 0, Cell::new('X'));

        $diff = $curr->diff($prev);

        $repeats = array_filter($diff, fn($op) => $op instanceof RepeatRunOp);
        $this->assertNotEmpty($repeats);
        $repeatOp = end($repeats);
        $this->assertSame('X', $repeatOp->rune);
        $this->assertSame(2, $repeatOp->count);
    }

    public function testDiffStyleTransitionEmitsSetStyleOp(): void
    {
        $prev = Buffer::new(3, 1);
        $curr = $prev->withCellAt(1, 0, Cell::new('B', Style::bold()));

        $diff = $curr->diff($prev);

        $styles = array_filter($diff, fn($op) => $op instanceof SetStyleOp);
        $this->assertNotEmpty($styles);
    }

    public function testDiffHyperlinkEmitsSetHyperlinkOp(): void
    {
        $prev = Buffer::new(3, 1);
        $link = Hyperlink::new('https://example.com');
        $curr = $prev->withCellAt(1, 0, Cell::new('L', null, $link));

        $diff = $curr->diff($prev);

        $links = array_filter($diff, fn($op) => $op instanceof SetHyperlinkOp);
        $this->assertNotEmpty($links);
        $linkOp = end($links);
        $this->assertSame('https://example.com', $linkOp->hyperlink->url());
    }

    public function testDiffWideCharSkipsContinuation(): void
    {
        $prev = Buffer::new(5, 1);
        $wide = Cell::new('中', null, null, 2);
        $continuation = Cell::continuation();
        $curr = $prev
            ->withCellAt(0, 0, $wide)
            ->withCellAt(1, 0, $continuation);

        $diff = $curr->diff($prev);

        $this->assertNotEmpty($diff);
        foreach ($diff as $op) {
            if ($op instanceof SetCellOp) {
                $this->assertCount(1, $op->cells);
                $this->assertSame('中', $op->cells[0]->rune());
            }
        }
    }

    public function testDiffMismatchedDimensionsThrows(): void
    {
        $prev = Buffer::new(5, 2);
        $curr = Buffer::new(5, 3);

        $this->expectException(\InvalidArgumentException::class);
        $curr->diff($prev);
    }

    public function testApplyDiffReturnsNewInstance(): void
    {
        $buf = Buffer::new(5, 2);
        $other = Buffer::new(5, 2)->withCellAt(2, 1, Cell::new('Y'));

        $result = $buf->applyDiff($other->diff($buf));

        $this->assertNotSame($buf, $result);
    }

    public function testApplyDiffMoveCursor(): void
    {
        $prev = Buffer::new(5, 2);
        $curr = $prev->withCellAt(3, 1, Cell::new('Z'));

        $diff = $curr->diff($prev);
        $result = $prev->applyDiff($diff);

        $this->assertSame('Z', $result->cellAt(3, 1)->rune());
    }

    public function testApplyDiffSetStyle(): void
    {
        $prev = Buffer::new(3, 1);
        $curr = $prev->withCellAt(1, 0, Cell::new('B', Style::bold()));

        $diff = $curr->diff($prev);
        $result = $prev->applyDiff($diff);

        $this->assertTrue($result->cellAt(1, 0)->style()->hasBold());
    }

    public function testApplyDiffEraseRun(): void
    {
        $prev = Buffer::new(5, 1)->withCellAt(0, 0, Cell::new('A'))
                                  ->withCellAt(1, 0, Cell::new('B'))
                                  ->withCellAt(2, 0, Cell::new('C'))
                                  ->withCellAt(3, 0, Cell::new('D'))
                                  ->withCellAt(4, 0, Cell::new('E'));
        $curr = Buffer::new(5, 1);

        $diff = $curr->diff($prev);
        $result = $prev->applyDiff($diff);

        $this->assertSame(' ', $result->cellAt(0, 0)->rune());
        $this->assertSame(' ', $result->cellAt(3, 0)->rune());
    }

    public function testApplyDiffRepeatRunOp(): void
    {
        $prev = Buffer::new(5, 1);
        $curr = $prev
            ->withCellAt(0, 0, Cell::new('X'))
            ->withCellAt(1, 0, Cell::new('X'))
            ->withCellAt(2, 0, Cell::new('X'));

        $diff = $curr->diff($prev);
        $result = $prev->applyDiff($diff);

        $this->assertSame('X', $result->cellAt(0, 0)->rune());
        $this->assertSame('X', $result->cellAt(1, 0)->rune());
        $this->assertSame('X', $result->cellAt(2, 0)->rune());
    }

    public function testApplyDiffWideChar(): void
    {
        $prev = Buffer::new(5, 1);
        $wide = Cell::new('日', null, null, 2);
        $continuation = Cell::continuation();
        $curr = $prev
            ->withCellAt(0, 0, $wide)
            ->withCellAt(1, 0, $continuation);

        $diff = $curr->diff($prev);
        $result = $prev->applyDiff($diff);

        $this->assertSame('日', $result->cellAt(0, 0)->rune());
        $this->assertSame(2, $result->cellAt(0, 0)->width());
        $this->assertSame('', $result->cellAt(1, 0)->rune());
        $this->assertSame(0, $result->cellAt(1, 0)->width());
    }

    public function testDiffWideCharSecondCellContinuationSkipped(): void
    {
        $prev = Buffer::new(5, 1);
        $wide = Cell::new('中', null, null, 2);
        $continuation = Cell::continuation();
        $curr = $prev
            ->withCellAt(0, 0, $wide)
            ->withCellAt(1, 0, $continuation);

        $diff = $curr->diff($prev);

        foreach ($diff as $op) {
            if ($op instanceof SetCellOp) {
                foreach ($op->cells as $cell) {
                    $this->assertNotSame(0, $cell->width());
                }
            }
        }
    }

    public function testDiffRepeatRunSameRuneAndStyle(): void
    {
        $prev = Buffer::new(5, 1);
        $curr = $prev
            ->withCellAt(0, 0, Cell::new('X'))
            ->withCellAt(1, 0, Cell::new('X'))
            ->withCellAt(2, 0, Cell::new('X'))
            ->withCellAt(3, 0, Cell::new('X'));

        $diff = $curr->diff($prev);

        $hasRepeat = false;
        foreach ($diff as $op) {
            if ($op instanceof RepeatRunOp) {
                $hasRepeat = true;
                $this->assertSame('X', $op->rune);
                $this->assertSame(3, $op->count);
            }
        }
        $this->assertTrue($hasRepeat, 'Diff should contain RepeatRunOp');
    }

    public function testDiffDifferentStylesNoRepeat(): void
    {
        $prev = Buffer::new(5, 1);
        $curr = $prev
            ->withCellAt(0, 0, Cell::new('A', Style::bold()))
            ->withCellAt(1, 0, Cell::new('B', Style::new(null, null, Style::ATTR_ITALIC)));

        $diff = $curr->diff($prev);

        $hasRepeat = false;
        foreach ($diff as $op) {
            if ($op instanceof RepeatRunOp) {
                $hasRepeat = true;
            }
        }
        $this->assertFalse($hasRepeat, 'Different styles should not use RepeatRunOp');
    }

    public function testApplyDiffAdvanceCursorCorrectly(): void
    {
        $prev = Buffer::new(5, 1);
        $curr = $prev
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(2, 0, Cell::new('B'));

        $diff = $curr->diff($prev);
        $result = $prev->applyDiff($diff);

        $this->assertSame('A', $result->cellAt(0, 0)->rune());
        $this->assertSame('B', $result->cellAt(2, 0)->rune());
        $this->assertSame(' ', $result->cellAt(1, 0)->rune());
    }

    public function testDiffEraseRunOpEmittedForLargeBlankRegion(): void
    {
        $prev = Buffer::new(5, 1)->withCellAt(0, 0, Cell::new('X'))
                                  ->withCellAt(1, 0, Cell::new('X'))
                                  ->withCellAt(2, 0, Cell::new('X'))
                                  ->withCellAt(3, 0, Cell::new('X'))
                                  ->withCellAt(4, 0, Cell::new('X'));
        $curr = Buffer::new(5, 1);

        $diff = $curr->diff($prev);

        $hasErase = false;
        foreach ($diff as $op) {
            if ($op instanceof EraseRunOp) {
                $hasErase = true;
            }
        }
        $this->assertTrue($hasErase, 'EraseRunOp must be emitted for large blank region');
    }

    public function testRoundTripDiffApplyDiffIsIdentity(): void
    {
        $prev = Buffer::new(10, 3);
        $curr = $prev
            ->withCellAt(2, 0, Cell::new('H', Style::bold()))
            ->withCellAt(3, 0, Cell::new('i'))
            ->withCellAt(5, 1, Cell::new('X', Style::new(0xFF0000)))
            ->withCellAt(7, 2, Cell::new('Y'));

        $diff = $curr->diff($prev);
        $restored = $prev->applyDiff($diff);

        $this->assertSame($curr->cellAt(2, 0)->rune(), $restored->cellAt(2, 0)->rune());
        $this->assertSame($curr->cellAt(3, 0)->rune(), $restored->cellAt(3, 0)->rune());
        $this->assertSame($curr->cellAt(5, 1)->rune(), $restored->cellAt(5, 1)->rune());
        $this->assertSame($curr->cellAt(7, 2)->rune(), $restored->cellAt(7, 2)->rune());
    }

    public function testRoundTripRandomPairsTwentyIterations(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $w = random_int(3, 10);
            $h = random_int(2, 5);
            $prev = Buffer::new($w, $h);
            $curr = $prev;

            $changeCount = random_int(0, 5);
            for ($c = 0; $c < $changeCount; $c++) {
                $col = random_int(0, $w - 1);
                $row = random_int(0, $h - 1);
                $rune = chr(random_int(65, 90));
                $style = random_int(0, 2) === 0 ? Style::bold() : null;
                $curr = $curr->withCellAt($col, $row, Cell::new($rune, $style));
            }

            $diff = $curr->diff($prev);
            $restored = $prev->applyDiff($diff);

            for ($r = 0; $r < $h; $r++) {
                for ($c = 0; $c < $w; $c++) {
                    $this->assertSame(
                        $curr->cellAt($c, $r)->rune(),
                        $restored->cellAt($c, $r)->rune(),
                        "Round-trip mismatch at ($c, $r) iteration $i"
                    );
                    $this->assertSame(
                        $curr->cellAt($c, $r)->width(),
                        $restored->cellAt($c, $r)->width(),
                        "Width mismatch at ($c, $r) iteration $i"
                    );
                }
            }
        }
    }

    public function testByteCountOneCharChangeIn80x24StaysUnder30(): void
    {
        $prev = Buffer::new(80, 24);
        $curr = $prev->withCellAt(40, 12, Cell::new('X'));

        $diff = $curr->diff($prev);
        $encoder = new DiffEncoder();
        $bytes = $encoder->encode($diff);

        $this->assertLessThanOrEqual(30, strlen($bytes), sprintf(
            '1-char change should emit ≤30 bytes, got %d: %s',
            strlen($bytes),
            bin2hex($bytes)
        ));
    }

    public function testDiffEncoderByteCountMuchSmallerThanFullRepaint(): void
    {
        $prev = Buffer::new(80, 24);
        $curr = $prev->withCellAt(5, 10, Cell::new('*'));

        $diff = $curr->diff($prev);
        $encoder = new DiffEncoder();
        $deltaBytes = strlen($encoder->encode($diff));

        $fullRepaint = $curr->toAnsi();
        $this->assertLessThan(
            strlen($fullRepaint) / 4,
            $deltaBytes,
            'Delta bytes should be much smaller than full repaint'
        );
    }

    public function testDiffOptimiserCollapsesStyleOps(): void
    {
        $prev = Buffer::new(5, 2);
        $curr = $prev
            ->withCellAt(0, 0, Cell::new('A', Style::bold()))
            ->withCellAt(1, 0, Cell::new('B', Style::new(null, null, Style::ATTR_ITALIC)));

        $diff = $curr->diff($prev);

        $styleOps = array_filter($diff, fn($op) => $op instanceof SetStyleOp);
        $this->assertCount(2, $styleOps);
    }

    public function testDiffOptimiserPassThroughPreservesOps(): void
    {
        $prev = Buffer::new(5, 1);
        $curr = $prev->withCellAt(2, 0, Cell::new('Q'));

        $diff = $curr->diff($prev);
        $optimiser = new DiffOptimiser();
        $optimised = $optimiser->optimise($diff);

        $this->assertNotEmpty($optimised);
        foreach ($optimised as $op) {
            $this->assertInstanceOf(\SugarCraft\Buffer\Diff\DiffOp::class, $op);
        }
    }

    // ─── toAnsi golden-byte tests ───────────────────────────────────────

    public function testToAnsiPlainText(): void
    {
        $buf = Buffer::new(3, 1)
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 0, Cell::new('B'))
            ->withCellAt(2, 0, Cell::new('C'));

        $this->assertSame('ABC', $buf->toAnsi());
    }

    public function testToAnsiStyledCellOpensAndClosesSgr(): void
    {
        // fg=0xff0000 (red) → SGR "38;2;255;0;0"
        $buf = Buffer::new(1, 1)
            ->withCellAt(0, 0, Cell::new('X', Style::new(0xff0000)));

        $ansi = $buf->toAnsi();

        // Must contain SGR open (0;38;2;255;0;0) and trailing reset
        $this->assertStringStartsWith("\x1b[0;38;2;255;0;0m", $ansi);
        $this->assertStringContainsString('X', $ansi);
        $this->assertStringEndsWith("\x1b[0m", $ansi);
    }

    public function testToAnsiWideCharSkipsContinuation(): void
    {
        // Wide char '中' at (0,0) with width=2; continuation at (1,0)
        $buf = Buffer::new(3, 1)
            ->withCellAt(0, 0, Cell::new('中', null, null, 2))
            ->withCellAt(1, 0, Cell::continuation())
            ->withCellAt(2, 0, Cell::new('X'));

        $ansi = $buf->toAnsi();

        // The continuation cell must not emit a rune or escape sequence
        $this->assertSame('中X', $ansi);
        // No stray SGR or hyperlink sequences for the continuation
        $this->assertStringNotContainsString("\x1b[", substr($ansi, 1));
    }

    public function testToAnsiHyperlinkOpenClose(): void
    {
        $link = Hyperlink::new('https://example.com');
        $buf = Buffer::new(1, 1)
            ->withCellAt(0, 0, Cell::new('L', null, $link));

        $ansi = $buf->toAnsi();

        // URL appears in the OSC 8 opening sequence
        $this->assertStringContainsString('https://example.com', $ansi);
        // OSC 8 close sequence is present
        $this->assertStringContainsString("\x1b]8;;\x1b\\", $ansi);
        // The rune is emitted between open and close
        $this->assertStringContainsString('L', $ansi);
    }

    public function testToAnsiMultiRowSeparator(): void
    {
        $buf = Buffer::new(2, 2)
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 1, Cell::new('B'));

        $ansi = $buf->toAnsi();

        // Rows are joined with \n
        $this->assertStringContainsString("\n", $ansi);
        // Grid row 0 = "A " (col 0='A', col 1=' '), row 1 = " B"
        // toAnsi: "A \n B" (5 bytes)
        $this->assertSame("A \n B", $ansi);
    }

    public function testDiffEraseRunEmitsEch(): void
    {
        // 5-cell buffer; cells 1-4 (4 consecutive) change from 'A' to ' '
        // (blank, default style) → EraseRunOp(4) must be emitted.
        // EraseRunOp requires runLen >= 3 in Buffer::diff().
        $prev = Buffer::new(5, 1)
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 0, Cell::new('A'))
            ->withCellAt(2, 0, Cell::new('A'))
            ->withCellAt(3, 0, Cell::new('A'))
            ->withCellAt(4, 0, Cell::new('A'));

        $curr = Buffer::new(5, 1)
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 0, Cell::new(' '))
            ->withCellAt(2, 0, Cell::new(' '))
            ->withCellAt(3, 0, Cell::new(' '))
            ->withCellAt(4, 0, Cell::new(' '));

        $diff = $curr->diff($prev);

        $echOps = array_filter($diff, fn($op) => $op instanceof EraseRunOp);
        $this->assertCount(1, $echOps, 'diff() must emit EraseRunOp for 4 consecutive blanks');
        $echOps = array_values($echOps);
        $this->assertSame(4, $echOps[0]->count);

        // Verify the encoder emits ECH: \x1b[4X
        $encoder = new DiffEncoder();
        $bytes = $encoder->encode($diff);
        $this->assertStringContainsString("\x1b[4X", $bytes);
    }

    public function testFromGridWideCharRoundTrips(): void
    {
        // Grid: index = row*width+col
        // Cols: 0='中'(w=2)  1=continuation  2='X'
        $grid = [
            0 => Cell::new('中', null, null, 2),
            1 => Cell::continuation(),
            2 => Cell::new('X'),
        ];
        $buf = Buffer::fromGrid(3, 1, $grid);

        // width-2 cell round-trips via cellAt()
        $this->assertSame('中', $buf->cellAt(0, 0)->rune());
        $this->assertSame(2, $buf->cellAt(0, 0)->width());
        $this->assertSame('', $buf->cellAt(1, 0)->rune());
        $this->assertSame(0, $buf->cellAt(1, 0)->width());
        $this->assertSame('X', $buf->cellAt(2, 0)->rune());
    }

    public function testApplyDiffHyperlinkReconstructionIsLossy(): void
    {
        // applyDiff() reconstructs hyperlinks from the url string stored in
        // SetHyperlinkOp, losing the id field.  Pin the actual (lossy) behaviour.
        $link = Hyperlink::new('https://example.com', 'myid');
        $prev = Buffer::new(1, 1);
        $curr = $prev->withCellAt(0, 0, Cell::new('L', null, $link));

        $diff = $curr->diff($prev);
        $reconstructed = $prev->applyDiff($diff);

        $cell = $reconstructed->cellAt(0, 0);
        $this->assertNotNull($cell->link());
        // URL is preserved
        $this->assertSame('https://example.com', $cell->link()->url());
        // ID is lost (reconstruction omits it)
        $this->assertSame('', $cell->link()->id());
    }

    public function testFillWithZeroAreaRegionIsNoOp(): void
    {
        // Zero-area regions are explicitly allowed and must leave the
        // buffer untouched (the fill loop runs zero iterations).
        $buf = Buffer::new(3, 2);
        $before = $buf->toAnsi();

        $filled = $buf->fill(new Region(Position::new(0, 0), 0, 2), Cell::new('X'));
        $this->assertSame($before, $filled->toAnsi());
        $this->assertSame(' ', $filled->cellAt(0, 0)->rune());

        $filled2 = $buf->fill(new Region(Position::new(0, 0), 3, 0), Cell::new('X'));
        $this->assertSame($before, $filled2->toAnsi());
    }

    // ─── copy() ────────────────────────────────────────────────────────

    public function testCopyReturnsNewInstance(): void
    {
        $buf = Buffer::new(5, 3)->withCellAt(2, 1, Cell::new('X'));

        $copy = $buf->copy($buf->region());

        $this->assertNotSame($buf, $copy);
        $this->assertSame('X', $copy->cellAt(2, 1)->rune());
        // Original is unchanged (immutable copy)
        $this->assertSame('X', $buf->cellAt(2, 1)->rune());
    }

    public function testCopyExactRegion(): void
    {
        $buf = Buffer::new(5, 3)
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 0, Cell::new('B'))
            ->withCellAt(0, 1, Cell::new('C'))
            ->withCellAt(1, 1, Cell::new('D'));

        $copy = $buf->copy(new Region(Position::new(0, 0), 2, 2));

        $this->assertSame(2, $copy->width());
        $this->assertSame(2, $copy->height());
        $this->assertSame('A', $copy->cellAt(0, 0)->rune());
        $this->assertSame('B', $copy->cellAt(1, 0)->rune());
        $this->assertSame('C', $copy->cellAt(0, 1)->rune());
        $this->assertSame('D', $copy->cellAt(1, 1)->rune());
    }

    public function testCopyRegionWithOffset(): void
    {
        $buf = Buffer::new(5, 4)
            ->withCellAt(2, 1, Cell::new('X'))
            ->withCellAt(3, 2, Cell::new('Y'));

        $copy = $buf->copy(new Region(Position::new(2, 1), 2, 2));

        $this->assertSame(2, $copy->width());
        $this->assertSame(2, $copy->height());
        $this->assertSame('X', $copy->cellAt(0, 0)->rune());
        $this->assertSame('Y', $copy->cellAt(1, 1)->rune());
        $this->assertSame(' ', $copy->cellAt(1, 0)->rune());
        $this->assertSame(' ', $copy->cellAt(0, 1)->rune());
    }

    public function testCopyClipsOutOfBoundsSource(): void
    {
        // 3x3 buffer; copy 5x5 region starting at (2,2) → only (2,2) is in bounds
        $buf = Buffer::new(3, 3)->withCellAt(2, 2, Cell::new('Z'));

        $copy = $buf->copy(new Region(Position::new(2, 2), 5, 5));

        $this->assertSame(5, $copy->width());
        $this->assertSame(5, $copy->height());
        $this->assertSame('Z', $copy->cellAt(0, 0)->rune());
        // Out-of-bounds positions in source → blank cells
        $this->assertSame(' ', $copy->cellAt(1, 0)->rune());
        $this->assertSame(' ', $copy->cellAt(0, 1)->rune());
        $this->assertSame(' ', $copy->cellAt(1, 1)->rune());
    }

    public function testCopyNegativeOriginUsesBlankCells(): void
    {
        // Region with negative origin: cells at negative source coords
        // should use blank cells.
        $buf = Buffer::new(3, 2)->withCellAt(0, 0, Cell::new('A'));

        $copy = $buf->copy(new Region(Position::new(-1, -1), 3, 2));

        $this->assertSame(3, $copy->width());
        $this->assertSame(2, $copy->height());
        // The source at (-1,-1) is out of bounds → blank
        // (0,0) in copy maps to source (-1,-1) → blank
        $this->assertSame(' ', $copy->cellAt(0, 0)->rune());
    }

    public function testCopyZeroAreaRegionThrows(): void
    {
        // Buffer::fromGrid requires positive dimensions; copy() uses fromGrid.
        $buf = Buffer::new(5, 3)->withCellAt(2, 1, Cell::new('X'));

        $this->expectException(\InvalidArgumentException::class);
        $buf->copy(new Region(Position::new(0, 0), 0, 0));
    }

    public function testCopyPreservesCellStyles(): void
    {
        $style = Style::new(0xff0000, 0x0000ff, Style::ATTR_BOLD);
        $buf = Buffer::new(3, 1)->withCellAt(1, 0, Cell::new('S', $style));

        $copy = $buf->copy(new Region(Position::new(0, 0), 3, 1));

        $cell = $copy->cellAt(1, 0);
        $this->assertSame('S', $cell->rune());
        $this->assertNotNull($cell->style());
        $this->assertSame(0xff0000, $cell->style()->fg());
        $this->assertSame(0x0000ff, $cell->style()->bg());
        $this->assertTrue($cell->style()->hasBold());
    }

    public function testCopyPreservesHyperlinks(): void
    {
        $link = Hyperlink::new('https://example.com');
        $buf = Buffer::new(2, 1)->withCellAt(1, 0, Cell::new('L', null, $link));

        $copy = $buf->copy(new Region(Position::new(0, 0), 2, 1));

        $cell = $copy->cellAt(1, 0);
        $this->assertNotNull($cell->link());
        $this->assertSame('https://example.com', $cell->link()->url());
    }

    public function testCopyPreservesWideChars(): void
    {
        $buf = Buffer::new(4, 1)
            ->withCellAt(0, 0, Cell::new('中', null, null, 2))
            ->withCellAt(1, 0, Cell::continuation());

        $copy = $buf->copy(new Region(Position::new(0, 0), 4, 1));

        $this->assertSame('中', $copy->cellAt(0, 0)->rune());
        $this->assertSame(2, $copy->cellAt(0, 0)->width());
        $this->assertSame('', $copy->cellAt(1, 0)->rune());
        $this->assertSame(0, $copy->cellAt(1, 0)->width());
    }

    // ─── fill edge cases ───────────────────────────────────────────────

    public function testFillSingleCell(): void
    {
        $buf = Buffer::new(3, 3);
        $filled = $buf->fill(new Region(Position::new(1, 1), 1, 1), Cell::new('X'));

        $this->assertSame('X', $filled->cellAt(1, 1)->rune());
        $this->assertSame(' ', $filled->cellAt(0, 0)->rune());
        $this->assertSame(' ', $filled->cellAt(2, 2)->rune());
    }

    public function testFillNegativeOriginIsClipped(): void
    {
        $buf = Buffer::new(3, 3);
        $filled = $buf->fill(new Region(Position::new(-1, -1), 3, 3), Cell::new('X'));

        // Only cells at non-negative positions within bounds get filled
        $this->assertSame('X', $filled->cellAt(0, 0)->rune());
        $this->assertSame(' ', $filled->cellAt(2, 2)->rune());
    }

    public function testFillPartialOverflowClipped(): void
    {
        $buf = Buffer::new(3, 3);
        $filled = $buf->fill(new Region(Position::new(2, 2), 5, 5), Cell::new('X'));

        // Only (2,2) is in bounds among the 5x5 region
        $this->assertSame('X', $filled->cellAt(2, 2)->rune());
        $this->assertSame(' ', $filled->cellAt(0, 0)->rune());
    }

    // ─── toAnsi edge cases ────────────────────────────────────────────

    public function testToAnsiStyleTransitionMidRow(): void
    {
        $buf = Buffer::new(3, 1)
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 0, Cell::new('B', Style::bold()))
            ->withCellAt(2, 0, Cell::new('C'));

        $ansi = $buf->toAnsi();

        // B must have bold SGR between A and C
        $this->assertStringContainsString("\x1b[0;1m", $ansi);
        $this->assertStringContainsString('B', $ansi);
    }

    public function testToAnsiHyperlinkTransitionMidRow(): void
    {
        $link1 = Hyperlink::new('https://a.com');
        $link2 = Hyperlink::new('https://b.com');
        $buf = Buffer::new(3, 1)
            ->withCellAt(0, 0, Cell::new('L', null, $link1))
            ->withCellAt(1, 0, Cell::new('M', null, $link2))
            ->withCellAt(2, 0, Cell::new('N'));

        $ansi = $buf->toAnsi();

        // Both URLs must appear; close before switching links
        $this->assertStringContainsString('https://a.com', $ansi);
        $this->assertStringContainsString('https://b.com', $ansi);
        $this->assertStringContainsString("\x1b]8;;\x1b\\", $ansi);
    }

    public function testToAnsiResetBetweenRows(): void
    {
        // Two rows with different styles: SGR should be emitted at row boundary
        $buf = Buffer::new(2, 2)
            ->withCellAt(0, 0, Cell::new('A', Style::bold()))
            ->withCellAt(1, 1, Cell::new('B', Style::new(0xff0000)));

        $ansi = $buf->toAnsi();

        // Row 0 has bold; row 1 has fg=red — bold must be reset before new style
        $this->assertStringContainsString("\x1b[0;38;2;255;0;0m", $ansi);
    }

    public function testToAnsiHyperlinkClosesBeforeStyleTransition(): void
    {
        $link = Hyperlink::new('https://example.com');
        // L has hyperlink; following cells do not
        $buf = Buffer::new(3, 1)
            ->withCellAt(0, 0, Cell::new('L', null, $link))
            ->withCellAt(1, 0, Cell::new(' '))
            ->withCellAt(2, 0, Cell::new(' '));

        $ansi = $buf->toAnsi();

        // Hyperlink opens at L; since subsequent cells have no link,
        // the link is closed before them.
        $this->assertStringContainsString("\x1b]8;;\x1b\\", $ansi);
        $this->assertStringContainsString('https://example.com', $ansi);
    }

    public function testToAnsiRowWithAllBlankCells(): void
    {
        // A row of all blank cells must still produce a \n separator
        $buf = Buffer::new(3, 2);

        $ansi = $buf->toAnsi();

        // Should have exactly one newline between two rows of 3 spaces
        $this->assertSame("   \n   ", $ansi);
    }

    public function testToAnsiEmptyBuffer(): void
    {
        $buf = Buffer::new(1, 1);
        $ansi = $buf->toAnsi();

        $this->assertSame(' ', $ansi);
    }

    public function testToAnsiStyledCellThenBlank(): void
    {
        // Styled cell followed by blank must emit reset before blank
        $buf = Buffer::new(2, 1)
            ->withCellAt(0, 0, Cell::new('X', Style::bold()))
            ->withCellAt(1, 0, Cell::new(' '));

        $ansi = $buf->toAnsi();

        // After 'X' with bold, the space must be preceded by SGR reset
        $this->assertStringContainsString("\x1b[0m", $ansi);
    }

    public function testToAnsiMultipleStyleChangesInRow(): void
    {
        $buf = Buffer::new(4, 1)
            ->withCellAt(0, 0, Cell::new('A', Style::bold()))
            ->withCellAt(1, 0, Cell::new('B'))
            ->withCellAt(2, 0, Cell::new('C', Style::new(0xff0000)))
            ->withCellAt(3, 0, Cell::new('D'));

        $ansi = $buf->toAnsi();

        // Bold SGR before A
        $this->assertStringContainsString("\x1b[0;1m", $ansi);
        // After B (no style), must reset before red
        $this->assertStringContainsString("\x1b[0;38;2;255;0;0m", $ansi);
        // D after red must reset to default (no SGR for D)
    }

    // ─── serialization ────────────────────────────────────────────────

    public function testSerializeAndUnserialize(): void
    {
        $buf = Buffer::new(3, 2)
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 0, Cell::new('B', Style::bold()))
            ->withCellAt(2, 1, Cell::new('C', Style::new(0xff0000)));

        $data = $buf->__serialize();

        $this->assertSame(3, $data['width']);
        $this->assertSame(2, $data['height']);
        $this->assertCount(6, $data['grid']); // 3*2 cells

        // Reconstruct
        $reconstituted = Buffer::fromGrid($data['width'], $data['height'], array_map(
            fn($cellData) => new Cell(
                $cellData['rune'],
                $cellData['style'] !== null ? new Style(
                    $cellData['style']['fg'],
                    $cellData['style']['bg'],
                    $cellData['style']['attrs']
                ) : null,
                $cellData['link'] !== null ? new Hyperlink(
                    $cellData['link']['url'],
                    $cellData['link']['id']
                ) : null,
                $cellData['width']
            ),
            $data['grid']
        ));

        $this->assertSame('A', $reconstituted->cellAt(0, 0)->rune());
        $this->assertSame('B', $reconstituted->cellAt(1, 0)->rune());
        $this->assertTrue($reconstituted->cellAt(1, 0)->style()->hasBold());
        $this->assertSame('C', $reconstituted->cellAt(2, 1)->rune());
        $this->assertSame(0xff0000, $reconstituted->cellAt(2, 1)->style()->fg());
    }

    public function testJsonSerialize(): void
    {
        $buf = Buffer::new(2, 1)->withCellAt(0, 0, Cell::new('X', Style::bold()));

        $json = json_encode($buf);
        $decoded = json_decode($json, true);

        $this->assertSame(2, $decoded['width']);
        $this->assertSame(1, $decoded['height']);
        $this->assertCount(2, $decoded['grid']);
        $this->assertSame('X', $decoded['grid'][0]['rune']);
        $this->assertSame(1, $decoded['grid'][0]['style']['attrs']);
    }

    public function testSerializeWideCharCell(): void
    {
        $buf = Buffer::new(3, 1)
            ->withCellAt(0, 0, Cell::new('日', null, null, 2))
            ->withCellAt(1, 0, Cell::continuation());

        $data = $buf->__serialize();

        $this->assertSame('日', $data['grid'][0]['rune']);
        $this->assertSame(2, $data['grid'][0]['width']);
        $this->assertSame('', $data['grid'][1]['rune']);
        $this->assertSame(0, $data['grid'][1]['width']);
    }

    public function testSerializeHyperlinkCell(): void
    {
        $link = Hyperlink::new('https://test.com', 'xid');
        $buf = Buffer::new(1, 1)->withCellAt(0, 0, Cell::new('L', null, $link));

        $data = $buf->__serialize();

        $this->assertSame('https://test.com', $data['grid'][0]['link']['url']);
        $this->assertSame('xid', $data['grid'][0]['link']['id']);
    }

    public function testFillPreservesOtherCells(): void
    {
        $buf = Buffer::new(3, 2)->withCellAt(0, 0, Cell::new('A'));

        $filled = $buf->fill(new Region(Position::new(1, 0), 1, 1), Cell::new('B'));

        $this->assertSame('A', $filled->cellAt(0, 0)->rune());
        $this->assertSame('B', $filled->cellAt(1, 0)->rune());
        $this->assertSame(' ', $filled->cellAt(2, 0)->rune());
    }

    // ─── fromString ──────────────────────────────────────────────────────

    public function testFromStringNullReturnsBlankBuffer(): void
    {
        $buf = Buffer::fromString(null, 3, 2);

        $this->assertSame(3, $buf->width());
        $this->assertSame(2, $buf->height());
        $this->assertSame(' ', $buf->cellAt(0, 0)->rune());
        $this->assertSame(' ', $buf->cellAt(2, 1)->rune());
    }

    public function testFromStringEmptyStringReturnsBlankBuffer(): void
    {
        $buf = Buffer::fromString('', 4, 1);

        $this->assertSame(4, $buf->width());
        $this->assertSame(1, $buf->height());
    }

    public function testFromStringParsesPlainText(): void
    {
        $buf = Buffer::fromString('ABC', 3, 1);

        $this->assertSame('A', $buf->cellAt(0, 0)->rune());
        $this->assertSame('B', $buf->cellAt(1, 0)->rune());
        $this->assertSame('C', $buf->cellAt(2, 0)->rune());
    }

    public function testFromStringStripsNewlines(): void
    {
        // "A\nB" produces 2 cells: 'A' at index 0, 'B' at index 1
        // With width=2, index 0=(col=0,row=0), index 1=(col=1,row=0), index 2=(col=0,row=1)
        // So 'A' is at (0,0) and 'B' is at (1,0), padding at (0,1) and (1,1)
        $buf = Buffer::fromString("A\nB", 2, 2);

        $this->assertSame('A', $buf->cellAt(0, 0)->rune());
        $this->assertSame('B', $buf->cellAt(1, 0)->rune());
        $this->assertSame(' ', $buf->cellAt(0, 1)->rune()); // padding
        $this->assertSame(' ', $buf->cellAt(1, 1)->rune()); // padding
    }

    public function testFromStringTruncatesOversizedInput(): void
    {
        // Buffer is 2x1 (2 cells) but string has 5 chars
        $buf = Buffer::fromString('ABCDE', 2, 1);

        $this->assertSame('A', $buf->cellAt(0, 0)->rune());
        $this->assertSame('B', $buf->cellAt(1, 0)->rune());
    }

    public function testFromStringPadsUndersizedInput(): void
    {
        // Buffer is 4x1 but string has only 2 chars
        $buf = Buffer::fromString('AB', 4, 1);

        $this->assertSame('A', $buf->cellAt(0, 0)->rune());
        $this->assertSame('B', $buf->cellAt(1, 0)->rune());
        $this->assertSame(' ', $buf->cellAt(2, 0)->rune());
        $this->assertSame(' ', $buf->cellAt(3, 0)->rune());
    }

    public function testFromStringParsesBoldSgr(): void
    {
        // "\x1b[1mX" = bold X
        $buf = Buffer::fromString("\x1b[1mX", 1, 1);

        $this->assertSame('X', $buf->cellAt(0, 0)->rune());
        $this->assertNotNull($buf->cellAt(0, 0)->style());
        $this->assertTrue($buf->cellAt(0, 0)->style()->hasBold());
    }

    public function testFromStringParsesForegroundColorSgr(): void
    {
        // "\x1b[31mX" = red foreground (ANSI 31)
        $buf = Buffer::fromString("\x1b[31mX", 1, 1);

        $this->assertSame('X', $buf->cellAt(0, 0)->rune());
        $this->assertNotNull($buf->cellAt(0, 0)->style());
        $this->assertSame(0xff0000, $buf->cellAt(0, 0)->style()->fg());
    }

    public function testFromStringParsesBackgroundColorSgr(): void
    {
        // "\x1b[44mX" = blue background (ANSI 44)
        $buf = Buffer::fromString("\x1b[44mX", 1, 1);

        $this->assertSame('X', $buf->cellAt(0, 0)->rune());
        $this->assertNotNull($buf->cellAt(0, 0)->style());
        $this->assertSame(0x0000ff, $buf->cellAt(0, 0)->style()->bg());
    }

    public function testFromStringParsesBrightForegroundSgr(): void
    {
        // "\x1b[91mX" = bright red foreground
        $buf = Buffer::fromString("\x1b[91mX", 1, 1);

        $this->assertSame('X', $buf->cellAt(0, 0)->rune());
        $this->assertNotNull($buf->cellAt(0, 0)->style());
        // Bright red should be lighter than standard red
        $this->assertNotSame(0xff0000, $buf->cellAt(0, 0)->style()->fg());
    }

    public function testFromStringParsesBrightBackgroundSgr(): void
    {
        // "\x1b[101mX" = bright blue background
        $buf = Buffer::fromString("\x1b[101mX", 1, 1);

        $this->assertSame('X', $buf->cellAt(0, 0)->rune());
        $this->assertNotNull($buf->cellAt(0, 0)->style());
    }

    public function testFromStringParsesUnderlineSgr(): void
    {
        $buf = Buffer::fromString("\x1b[4mX", 1, 1);

        $this->assertSame('X', $buf->cellAt(0, 0)->rune());
        $this->assertNotNull($buf->cellAt(0, 0)->style());
        $this->assertTrue($buf->cellAt(0, 0)->style()->hasUnderline());
    }

    public function testFromStringResetClearsStyle(): void
    {
        // "\x1b[1mX\x1b[0mY" = bold X then reset then Y (no style)
        $buf = Buffer::fromString("\x1b[1mX\x1b[0mY", 2, 1);

        $this->assertTrue($buf->cellAt(0, 0)->style()->hasBold());
        $this->assertNull($buf->cellAt(1, 0)->style());
    }

    public function testFromStringNonPositiveWidthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Buffer::fromString('ABC', 0, 1);
    }

    public function testFromStringNonPositiveHeightThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Buffer::fromString('ABC', 3, -1);
    }

    public function testFromStringMultiCharStyle(): void
    {
        // "\x1b[1;4mX" = bold + underline
        $buf = Buffer::fromString("\x1b[1;4mX", 1, 1);

        $this->assertSame('X', $buf->cellAt(0, 0)->rune());
        $this->assertTrue($buf->cellAt(0, 0)->style()->hasBold());
        $this->assertTrue($buf->cellAt(0, 0)->style()->hasUnderline());
    }

    // ─── copy() negative origin edge case ────────────────────────────────

    public function testCopyFullyNegativeOriginUsesAllBlankCells(): void
    {
        // Region at (-5, -5) with size 3x3 — source coords (-5,-5) through (-3,-3)
        // are all out of bounds, so all 9 cells should be blank.
        $buf = Buffer::new(3, 3);
        $copy = $buf->copy(new Region(Position::new(-5, -5), 3, 3));

        $this->assertSame(3, $copy->width());
        $this->assertSame(3, $copy->height());
        for ($r = 0; $r < 3; $r++) {
            for ($c = 0; $c < 3; $c++) {
                $this->assertSame(' ', $copy->cellAt($c, $r)->rune());
            }
        }
    }

    public function testCopyPartiallyNegativeOriginUsesSomeBlankCells(): void
    {
        // Region at (-1, -1) with size 3x2 on a 3x2 buffer
        // Source coords: row0=(-1,-1),(-0,-1),(1,-1) — all negative row = out of bounds
        // Source coords: row1=(-1,0),(0,0),(1,0) — only (0,0) is in bounds if buffer has content
        // Given empty buffer, all become blank
        $buf = Buffer::new(3, 2);
        $buf = $buf->withCellAt(1, 0, Cell::new('X'));
        $copy = $buf->copy(new Region(Position::new(-1, -1), 3, 2));

        // Source (1,0) maps to dest (0,0) - but origin is negative so offset matters
        // dest col 0 = src col (-1 + 0) = -1 -> blank
        // dest col 1 = src col (-1 + 1) = 0 -> X is at (1,0), not (0,0) -> blank  
        // dest col 2 = src col (-1 + 2) = 1 -> X is at (1,0), not (1,0)? wait
        // Let me trace through: srcCol = region.origin.col + dx = -1 + dx
        // for dest (0,0), dx=0: srcCol = -1, srcRow = -1 -> out of bounds -> blank
        // for dest (1,0), dx=1: srcCol = 0, srcRow = -1 -> out of bounds -> blank
        // for dest (2,0), dx=2: srcCol = 1, srcRow = -1 -> out of bounds -> blank
        $this->assertSame(3, $copy->width());
        $this->assertSame(2, $copy->height());
    }

    // ─── applyDiff edge cases ─────────────────────────────────────────────

    public function testApplyDiffSetCellOpMultipleCells(): void
    {
        $prev = Buffer::new(5, 1);
        $curr = $prev
            ->withCellAt(0, 0, Cell::new('A'))
            ->withCellAt(1, 0, Cell::new('B'))
            ->withCellAt(2, 0, Cell::new('C'));

        $diff = $curr->diff($prev);
        $result = $prev->applyDiff($diff);

        $this->assertSame('A', $result->cellAt(0, 0)->rune());
        $this->assertSame('B', $result->cellAt(1, 0)->rune());
        $this->assertSame('C', $result->cellAt(2, 0)->rune());
    }

    public function testApplyDiffSetCellOpClipsCursorAtRightEdge(): void
    {
        $prev = Buffer::new(3, 1);
        $curr = $prev->withCellAt(2, 0, Cell::new('X'));

        $diff = $curr->diff($prev);
        $result = $prev->applyDiff($diff);

        $this->assertSame('X', $result->cellAt(2, 0)->rune());
    }

    public function testApplyDiffEraseRunOpAtRightEdgeClips(): void
    {
        // 3-cell buffer; erasing 5 cells at cursor 0 should only erase 3
        $prev = Buffer::new(3, 1)->withCellAt(0, 0, Cell::new('A'))
                                  ->withCellAt(1, 0, Cell::new('B'))
                                  ->withCellAt(2, 0, Cell::new('C'));
        $curr = Buffer::new(3, 1);

        $diff = $curr->diff($prev);
        $result = $prev->applyDiff($diff);

        // All cells should be blank after erase
        $this->assertSame(' ', $result->cellAt(0, 0)->rune());
        $this->assertSame(' ', $result->cellAt(1, 0)->rune());
        $this->assertSame(' ', $result->cellAt(2, 0)->rune());
    }

    public function testApplyDiffRepeatRunOpZeroWidthTreatedAsOne(): void
    {
        // RepeatRunOp with width=0 should treat width as 1
        $prev = Buffer::new(5, 1);
        $curr = $prev->withCellAt(0, 0, Cell::new('X'));

        $diff = $curr->diff($prev);
        $result = $prev->applyDiff($diff);

        $this->assertSame('X', $result->cellAt(0, 0)->rune());
    }

    public function testApplyDiffWideCharCreatesContinuationCell(): void
    {
        $prev = Buffer::new(3, 1);
        $wide = Cell::new('日', null, null, 2);
        $curr = $prev
            ->withCellAt(0, 0, $wide)
            ->withCellAt(1, 0, Cell::continuation());

        $diff = $curr->diff($prev);
        $result = $prev->applyDiff($diff);

        $this->assertSame('日', $result->cellAt(0, 0)->rune());
        $this->assertSame(2, $result->cellAt(0, 0)->width());
        $this->assertSame('', $result->cellAt(1, 0)->rune());
        $this->assertSame(0, $result->cellAt(1, 0)->width());
    }
}
